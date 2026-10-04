"use strict";
const byId = (id) => document.getElementById(id);
const form = byId("generate-form"), promptField = byId("prompt"), generate = byId("generate");
const statusBox = byId("status"), errorBox = byId("error"), resume = byId("resume");
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let taskId = document.body.dataset.lastId || "", busy = false, pollTimer;
const terminal = new Set(["READY", "FAILED", "CANCELED", "SUBMISSION_UNKNOWN"]);
const labels = {PENDING:"طلبك في انتظار التوليد…",THROTTLED:"طلبك ينتظر دوره لدى المزود…",RUNNING:"جارٍ إنشاء المقطع…",SAVING:"اكتمل التوليد، جارٍ حفظ الفيديو…",READY:"مقطعك جاهز للمشاهدة والتنزيل",FAILED:"تعذر إكمال التوليد",CANCELED:"أُلغي الطلب",SUBMITTING:"جارٍ إرسال وصفك…",SUBMISSION_UNKNOWN:"نحتاج التأكد من الطلب في لوحة Runway"};
function lock(value) { busy=value; generate.disabled=value || document.body.dataset.ready!=="1"; promptField.disabled=value; byId("example").disabled=value; }
function showError(message) { errorBox.textContent=message; errorBox.hidden=false; }
async function api(url, options={}) {
    const response=await fetch(url,{credentials:"same-origin",...options});
    let data;
    try { data=await response.json(); } catch { throw new Error("لم يصل رد صالح من الخادم. تابع نفس الطلب قبل بدء توليد آخر."); }
    if (!response.ok) { const error=new Error(data.error || "تعذر إكمال الطلب."); error.http=response.status; throw error; }
    return data;
}
function render(task) {
    taskId=task.id; statusBox.textContent=labels[task.status] || "جارٍ متابعة الطلب…";
    byId("detail").textContent="رقم طلبك المحلي: " + task.id + (task.provider_task_id ? " · Runway: " + task.provider_task_id : "");
    if (task.prompt) { promptField.value=task.prompt; byId("counter").textContent=promptField.value.length+" / 1000"; }
    if (task.status==="READY") {
        byId("placeholder").hidden=true;
        const video=byId("video"); video.hidden=false;
        if (video.getAttribute("src")!==task.video_url) { video.src=task.video_url; video.load(); }
        byId("download").href=task.download_url; byId("download").hidden=false;
    }
    if (task.message) showError(task.message);
    if (terminal.has(task.status)) {
        lock(task.status==="SUBMISSION_UNKNOWN"); resume.hidden=true;
        if (task.status!=="SUBMISSION_UNKNOWN") sessionStorage.removeItem("mask_pending");
        return false;
    }
    lock(true); return true;
}
async function poll() {
    clearTimeout(pollTimer); resume.hidden=true; errorBox.hidden=true;
    try {
        const task=await api("status.php?id="+encodeURIComponent(taskId));
        if (render(task)) pollTimer=setTimeout(poll,5000);
    } catch (error) { showError(error.message); resume.hidden=false; lock(true); }
}
form.addEventListener("submit",async(event)=>{
    event.preventDefault(); if(busy) return;
    const prompt=promptField.value.trim(); if(!prompt || prompt.length>1000) return;
    const pending={id:Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,"0")).join(""),prompt};
    sessionStorage.setItem("mask_pending",JSON.stringify(pending));
    taskId=pending.id; lock(true); errorBox.hidden=true; resume.hidden=true;
    byId("video").hidden=true; byId("download").hidden=true; byId("placeholder").hidden=false;
    statusBox.textContent="جارٍ إرسال وصفك…";
    try {
        const task=await api("generate.php",{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":csrf},body:JSON.stringify({prompt,request_id:pending.id})});
        if(render(task)) pollTimer=setTimeout(poll,5000);
    } catch(error) { showError(error.message); if(error.http) { sessionStorage.removeItem("mask_pending"); lock(false); resume.hidden=true; } else { resume.hidden=false; lock(true); } }
});
resume.addEventListener("click",poll);
promptField.addEventListener("input",()=>{byId("counter").textContent=promptField.value.length+" / 1000";});
byId("example").addEventListener("click",()=>{
    promptField.value="سيارة رياضية سوداء فاخرة في شارع حديث ليلًا، إضاءة نيون زرقاء تنعكس على هيكل السيارة. تبدأ الكاميرا بلقطة أمامية منخفضة ثم تتحرك ببطء إلى الجانب بينما تبدأ السيارة بالحركة. تصوير سينمائي وحركة ناعمة، دون نصوص على الشاشة.";
    promptField.dispatchEvent(new Event("input")); promptField.focus();
});
const pendingRaw=sessionStorage.getItem("mask_pending");
if(!taskId && pendingRaw){try{taskId=JSON.parse(pendingRaw).id || "";}catch{sessionStorage.removeItem("mask_pending");}}
if(taskId) { lock(true); poll(); }
