<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
sales_head('Legal Documents');
sales_header($me, 'Governance · Legal');
sales_nav('legal');
?>
<main class="p-3 space-y-2">

  <div class="flex items-center gap-3 flex-wrap">
    <span class="text-sm font-bold text-gray-700 tracking-wide">LEGAL DOCUMENTS</span>
    <span class="text-xs text-gray-400">— published documents appear on payg.in immediately</span>
    <div class="flex-1"></div>
    <button onclick="newDoc()" class="btn-p"><i class="fa-solid fa-plus"></i> New document</button>
  </div>

  <div class="card">
    <div class="overflow-x-auto"><table class="dense"><thead><tr>
      <th>Document</th><th>Slug (website URL key)</th><th>Status</th><th>Size</th>
      <th>Last edited</th><th>By</th><th class="text-right">Versions</th><th></th>
    </tr></thead><tbody id="docRows"></tbody></table></div>
  </div>

  <!-- ============ editor ============ -->
  <div id="editorCard" class="card p-3 hidden">
    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
      <div>
        <input id="edTitle" class="ctl font-semibold" style="min-width:320px;font-size:14px">
        <span id="edStatus" class="ml-2"></span>
      </div>
      <div class="flex gap-2">
        <button onclick="saveDoc()" class="btn-p"><i class="fa-solid fa-floppy-disk"></i> Save draft</button>
        <button id="pubBtn" onclick="publishDoc()" class="bg-green-600 hover:bg-green-700 text-white rounded px-3 text-xs"><i class="fa-solid fa-rocket"></i> Publish to website</button>
        <button id="unpubBtn" onclick="unpublishDoc()" class="bg-amber-100 text-amber-700 rounded px-3 text-xs hidden">Unpublish</button>
        <button onclick="closeEditor()" class="ctl hover:bg-gray-50">Close</button>
      </div>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Content (HTML)</div>
        <textarea id="edContent" rows="24" class="w-full border rounded-lg p-3 font-mono text-[11px]" oninput="previewDoc()"></textarea>
      </div>
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Preview — as it appears on payg.in</div>
        <div id="edPreview" class="border rounded-lg p-4 text-[13px] leading-relaxed overflow-y-auto bg-white" style="max-height:560px"></div>
      </div>
    </div>
    <div class="mt-3">
      <div class="text-[10px] uppercase text-gray-400 mb-1">Version history <span class="normal-case">(every save is snapshotted — click restore to bring one back as the current draft)</span></div>
      <div class="overflow-x-auto max-h-40 overflow-y-auto"><table class="dense"><thead><tr>
        <th>Saved</th><th>By</th><th>Was</th><th>Size</th><th></th>
      </tr></thead><tbody id="revRows"></tbody></table></div>
    </div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
let DOCS = [], CUR = null;

async function load(){
  const { data } = await apiGet('legal.php', { list:1 });
  DOCS = data||[];
  document.getElementById('docRows').innerHTML = DOCS.map(d=>`
    <tr>
      <td class="font-semibold">${esc(d.title)}</td>
      <td class="font-mono text-[10px] text-gray-500">${esc(d.slug)}</td>
      <td>${d.status==='published' ? badge('LIVE on payg.in','green') : badge('draft','yellow')}</td>
      <td class="text-[11px] text-gray-500">${(d.content_len/1024).toFixed(1)} KB</td>
      <td class="text-[10px] text-gray-400">${esc((d.updated_at||'').slice(0,16))}</td>
      <td class="text-[11px]">${esc(d.updated_by_name||'—')}</td>
      <td class="text-right">${d.revisions}</td>
      <td class="rowact"><button onclick="openDoc(${d.id})" class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded">Edit</button></td>
    </tr>`).join('') || '<tr><td colspan="8" class="text-center text-gray-400 py-4">No documents yet</td></tr>';
}

async function openDoc(id){
  const { data } = await apiGet('legal.php', { id });
  CUR = data;
  document.getElementById('editorCard').classList.remove('hidden');
  document.getElementById('edTitle').value = data.title;
  document.getElementById('edContent').value = data.content||'';
  document.getElementById('edStatus').innerHTML = data.status==='published' ? badge('LIVE','green') : badge('draft','yellow');
  document.getElementById('unpubBtn').classList.toggle('hidden', data.status!=='published');
  document.getElementById('revRows').innerHTML = (data.revision_list||[]).map(r=>`
    <tr><td class="text-[10px] text-gray-400">${esc((r.created_at||'').slice(0,16))}</td>
    <td class="text-[11px]">${esc(r.saved_by_name||'—')}</td>
    <td>${badge(r.status, r.status==='published'?'green':'gray')}</td>
    <td class="text-[11px]">${(r.content_len/1024).toFixed(1)} KB</td>
    <td class="rowact"><button onclick="restoreRev(${r.id})" class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded">Restore</button></td></tr>`).join('')
    || '<tr><td colspan="5" class="text-gray-400 text-center py-2 text-xs">No versions yet</td></tr>';
  previewDoc();
  document.getElementById('editorCard').scrollIntoView({behavior:'smooth'});
}
function previewDoc(){
  document.getElementById('edPreview').innerHTML = document.getElementById('edContent').value;
}
function closeEditor(){ CUR=null; document.getElementById('editorCard').classList.add('hidden'); }

async function saveDoc(){
  if (!CUR) return;
  const res = await apiSend('legal.php?id='+CUR.id, { title:document.getElementById('edTitle').value,
    content:document.getElementById('edContent').value }, 'PATCH');
  if (res.success){ toast('Draft saved (previous version kept in history)'); openDoc(CUR.id); load(); }
  else toast(res.error||'Failed', false);
}
async function publishDoc(){
  if (!CUR) return;
  await saveQuiet();
  if (!confirm('Publish this document? It goes LIVE on payg.in immediately.')) return;
  const res = await apiSend('legal.php?action=publish&id='+CUR.id, {});
  if (res.success){ toast('Published — live on the website now'); openDoc(CUR.id); load(); }
  else toast(res.error||'Failed', false);
}
async function saveQuiet(){
  try { await apiSend('legal.php?id='+CUR.id, { title:document.getElementById('edTitle').value,
    content:document.getElementById('edContent').value }, 'PATCH'); } catch(e){}
}
async function unpublishDoc(){
  if (!confirm('Unpublish? The website falls back to its built-in copy of this page.')) return;
  const res = await apiSend('legal.php?action=unpublish&id='+CUR.id, {});
  if (res.success){ toast('Unpublished'); openDoc(CUR.id); load(); } else toast(res.error||'Failed', false);
}
async function restoreRev(rid){
  if (!confirm('Restore this version as the current draft? (The current content is snapshotted first.)')) return;
  const res = await apiSend('legal.php?action=restore&revision='+rid, {});
  if (res.success){ toast('Version restored as draft'); openDoc(CUR.id); } else toast(res.error||'Failed', false);
}
function newDoc(){
  openModal(`<form onsubmit="createDoc(event)" class="space-y-2 text-xs">
    <label class="block">Title<input name="title" required class="ctl w-full mt-0.5" placeholder="e.g. Cookie Policy"></label>
    <label class="block">Slug (website URL key)<input name="slug" required class="ctl w-full mt-0.5" placeholder="cookie-policy"></label>
    <div class="flex justify-end gap-2 pt-1"><button type="button" onclick="closeModal()" class="px-3 py-1.5 border rounded">Cancel</button>
    <button class="btn-p">Create</button></div></form>`, 'New legal document');
}
async function createDoc(e){
  e.preventDefault(); const f = e.target;
  const res = await apiSend('legal.php', { title:f.title.value, slug:f.slug.value });
  if (res.success){ closeModal(); toast('Created'); load(); openDoc(res.data.id); } else toast(res.error||'Failed', false);
}
load();
</script>
</html>
