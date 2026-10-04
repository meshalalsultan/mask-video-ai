<?php
declare(strict_types=1);

final class AppError extends RuntimeException
{
    public function __construct(string $message, public readonly int $http = 400, public readonly bool $uncertain = false)
    {
        parent::__construct($message);
    }
}

function validatePrompt(mixed $input): string
{
    if (!is_string($input) || !mb_check_encoding($input, 'UTF-8')) {
        throw new AppError('الوصف يجب أن يكون نصًا صالحًا.');
    }
    $prompt = trim($input);
    // Runway يقيس الحد بوحدات UTF-16؛ الرموز التعبيرية قد تساوي وحدتين.
    $units = strlen(mb_convert_encoding($prompt, 'UTF-16LE', 'UTF-8')) / 2;
    if ($units < 1 || $units > 1000) {
        throw new AppError('اكتب وصفًا بين حرف واحد و1000 وحدة نصية.');
    }
    return $prompt;
}

function validId(string $id): bool
{
    return preg_match('/^[a-f0-9]{32}$/D', $id) === 1;
}

function parseRange(?string $header, int $size): array
{
    if ($header === null) {
        return [0, $size - 1, false];
    }
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $header, $m) || ($m[1] === '' && $m[2] === '')) {
        throw new AppError('نطاق غير صالح.', 416);
    }
    if ($m[1] === '') {
        $suffix = (int) $m[2];
        if ($suffix < 1) { throw new AppError('نطاق غير صالح.', 416); }
        $start = max(0, $size - $suffix);
        $end = $size - 1;
    } else {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
    }
    if ($start >= $size || $end < $start) {
        throw new AppError('نطاق غير صالح.', 416);
    }
    return [$start, $end, true];
}

final class TaskStore
{
    public function __construct(private readonly string $directory) {}

    public function path(string $id): string
    {
        if (!validId($id)) { throw new AppError('معرّف الطلب غير صالح.'); }
        return $this->directory . '/' . $id . '.json';
    }

    public function read(string $id, string $owner): array
    {
        $path = $this->path($id);
        if (!is_file($path)) { throw new AppError('لم يتم العثور على الطلب.', 404); }
        $task = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($task) || !hash_equals($task['owner'] ?? '', $owner)) {
            throw new AppError('لم يتم العثور على الطلب.', 404);
        }
        return $task;
    }

    public function write(array $task): void
    {
        $path = $this->path($task['id']);
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, json_encode($task, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new AppError('تعذر حفظ بيانات الطلب.', 500);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new AppError('تعذر حفظ بيانات الطلب.', 500);
        }
    }
}

final class RunwayClient
{
    public function __construct(private readonly array $config, private readonly ?Closure $transport = null) {}

    public function create(string $prompt): string
    {
        $data = $this->request('POST', '/v1/text_to_video', [
            'model' => $this->config['model'], 'promptText' => validatePrompt($prompt),
            'duration' => $this->config['duration'], 'ratio' => $this->config['ratio'],
        ]);
        $id = $data['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $id)) {
            throw new AppError('رد التوليد غير واضح. راجع لوحة Runway قبل إنشاء طلب جديد.', 502, true);
        }
        return $id;
    }

    public function status(string $id): array
    {
        if (!preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $id)) { throw new AppError('معرّف المزود غير صالح.'); }
        $data = $this->request('GET', '/v1/tasks/' . $id);
        $allowed = ['PENDING', 'THROTTLED', 'RUNNING', 'SUCCEEDED', 'FAILED', 'CANCELED'];
        if (!in_array($data['status'] ?? '', $allowed, true)) {
            throw new AppError('حالة غير معروفة من المزود. أعد متابعة الطلب بعد قليل.', 502);
        }
        return $data;
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->transport !== null) {
            [$code, $raw] = ($this->transport)($method, $path, $body);
        } else {
            $ch = curl_init('https://api.dev.runwayml.com' . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->config['api_key'],
                    'X-Runway-Version: ' . $this->config['api_version'],
                    'Content-Type: application/json', 'Accept: application/json',
                ],
            ]);
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR)); }
            $raw = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($raw === false) {
                throw new AppError($method === 'POST'
                    ? 'انقطع الاتصال أثناء إرسال الطلب. قد يكون التوليد بدأ؛ راجع لوحة Runway قبل إرسال طلب جديد.'
                    : 'تعذر الاتصال بRunway. يمكنك متابعة نفس الطلب بعد قليل.', 502, $method === 'POST');
            }
        }
        // لا نعيد رسائل المزود الخام أو روابطه الموقعة أو مفتاح API للمتصفح.
        if ($code < 200 || $code >= 300) {
            $message = match ($code) {
                401, 403 => 'تحقق من مفتاح Runway وصلاحيات المشروع.',
                402 => 'رصيد Runway غير كافٍ. تحقق من حساب المطور.',
                429 => 'وصل الحساب إلى حد الطلبات. انتظر قليلًا.',
                400, 422 => 'رفض المزود إعدادات الطلب أو محتواه. راجع الوصف وإعدادات النموذج.',
                default => 'خطأ من Runway. راجع لوحة الحساب قبل تكرار طلب التوليد.',
            };
            throw new AppError($message, $code === 429 ? 429 : 502, $method === 'POST' && $code >= 500);
        }
        try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new AppError('رد المزود غير صالح. راجع لوحة Runway قبل تكرار التوليد.', 502, $method === 'POST'); }
        if (!is_array($data)) { throw new AppError('رد المزود غير صالح.', 502, $method === 'POST'); }
        return $data;
    }
}

function downloadVideo(string $url, string $destination): void
{
    $parts = parse_url($url);
    $host = $parts['host'] ?? '';
    if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])
        || (isset($parts['port']) && $parts['port'] !== 443) || filter_var($host, FILTER_VALIDATE_IP)) {
        throw new AppError('رابط نتيجة المزود غير صالح.', 502);
    }
    // تثبيت عنوان عام في اتصال cURL لمنع الوصول للشبكات الداخلية وإعادة ربط DNS.
    $ips = gethostbynamel($host) ?: [];
    if ($ips === []) { throw new AppError('تعذر الوصول لخادم الفيديو. أعد المتابعة.', 502); }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new AppError('عنوان خادم الفيديو غير صالح.', 502);
        }
    }
    $temporary = $destination . '.part';
    $file = fopen($temporary, 'wb');
    if (!$file) { throw new AppError('تعذر حفظ الفيديو. تحقق من صلاحيات التخزين.', 500); }
    $bytes = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
        CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($file, &$bytes): int {
            $bytes += strlen($chunk);
            if ($bytes > 100 * 1024 * 1024) { return 0; }
            return fwrite($file, $chunk) ?: 0;
        },
    ]);
    // لا يُرسل مفتاح Runway إلى خادم تنزيل الفيديو.
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($file);
    $probe = fopen($temporary, 'rb');
    $header = $probe ? fread($probe, 12) : '';
    if ($probe) { fclose($probe); }
    if ($ok === false || $code !== 200 || $bytes < 12 || substr($header, 4, 4) !== 'ftyp') {
        @unlink($temporary);
        throw new AppError('اكتمل التوليد لكن تعذر حفظ MP4. أعد متابعة نفس الطلب؛ لن يُعاد التوليد.', 502);
    }
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        throw new AppError('تعذر تثبيت ملف الفيديو.', 500);
    }
}
