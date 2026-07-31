<?php
require_once __DIR__ . '/includes/sales-layout.php';
$me = sales_guard();
$isAdmin = sales_role() === 'admin';
sales_head('Documents');
sales_header($me, 'Documents');
sales_nav('documents');
?>
<main class="p-2">
  <div class="grid grid-cols-1 lg:grid-cols-4 gap-2">

    <!-- ============ folder rail ============ -->
    <div class="space-y-2">
      <div class="card p-2 flex items-center gap-2">
        <button onclick="newFolder()" class="btn-p flex-1"><i class="fa-solid fa-folder-plus"></i> New folder</button>
      </div>
      <div class="card p-2">
        <div id="folderSections"></div>
      </div>
    </div>

    <!-- ============ files ============ -->
    <div class="lg:col-span-3 space-y-2">
      <div id="folderHead" class="card p-2 flex items-center gap-2 flex-wrap hidden">
        <i class="fa-solid fa-folder-open text-indigo-500"></i>
        <b id="fhName" class="text-sm"></b>
        <span id="fhBadges" class="flex gap-1"></span>
        <div class="flex-1"></div>
        <span id="fhOwner" class="text-[10px] text-gray-400"></span>
        <button id="fhShare" onclick="openShare()" class="text-[11px] bg-indigo-100 text-indigo-700 px-2 py-1 rounded hidden"><i class="fa-solid fa-user-plus"></i> Share</button>
        <button id="fhRename" onclick="renameFolder()" class="text-[11px] bg-gray-100 text-gray-700 px-2 py-1 rounded hidden"><i class="fa-solid fa-pen"></i> Rename</button>
        <button id="fhDelete" onclick="deleteFolder()" class="text-[11px] bg-red-100 text-red-700 px-2 py-1 rounded hidden"><i class="fa-solid fa-trash"></i> Delete</button>
      </div>

      <!-- drop zone + file list -->
      <div id="dropZone" class="card relative min-h-[380px]">
        <div id="dzHint" class="hidden absolute inset-0 z-20 bg-indigo-50/90 border-2 border-dashed border-indigo-500 rounded-lg flex items-center justify-center">
          <div class="text-indigo-700 text-sm font-semibold"><i class="fa-solid fa-cloud-arrow-up text-2xl"></i><br>Drop files to upload</div>
        </div>
        <div class="p-2 border-b flex items-center gap-2">
          <span class="text-[11px] font-semibold text-gray-600 uppercase tracking-wide">Files</span>
          <span id="fileCount" class="text-[10px] text-gray-400"></span>
          <div class="flex-1"></div>
          <label id="uploadBtn" class="btn-p cursor-pointer hidden"><i class="fa-solid fa-upload"></i> Upload
            <input id="fileInput" type="file" multiple class="hidden" onchange="uploadFiles(this.files)">
          </label>
        </div>
        <div class="overflow-x-auto"><table class="dense">
          <thead><tr><th></th><th>Name</th><th>Size</th><th>Uploaded by</th><th>When</th><th></th></tr></thead>
          <tbody id="fileRows"><tr><td colspan="6" class="text-center text-gray-400 py-10">Select a folder on the left</td></tr></tbody>
        </table></div>
        <div id="upProgress" class="hidden p-2 text-[11px] text-indigo-600"><i class="fa-solid fa-spinner fa-spin"></i> Uploading…</div>
      </div>
    </div>
  </div>
</main>

<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b"><h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button></div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>

<?php sales_scripts(); ?>
<script>
const IS_ADMIN = <?= $isAdmin ? 'true':'false' ?>;
let FOLDERS = [], CUR = null;   // CUR = {id,name,can_edit,is_mine,visibility}

const fmtBytes = n => { n=+n||0; return n>=1048576 ? (n/1048576).toFixed(1)+' MB' : n>=1024 ? (n/1024).toFixed(0)+' KB' : n+' B'; };
const FICON = m => { m=m||''; if(m.startsWith('image/'))return 'fa-file-image text-purple-500';
  if(m.includes('pdf'))return 'fa-file-pdf text-red-500'; if(m.includes('sheet')||m.includes('excel')||m.includes('csv'))return 'fa-file-excel text-green-600';
  if(m.includes('word'))return 'fa-file-word text-blue-600'; if(m.includes('zip')||m.includes('compressed'))return 'fa-file-zipper text-amber-500';
  return 'fa-file text-gray-400'; };

async function loadTree(keep){
  const { data } = await apiGet('documents.php', { tree:1 });
  FOLDERS = data || [];
  const sec = (label, arr) => arr.length ? `
    <div class="px-1 pt-2 pb-1 text-[10px] uppercase tracking-wide text-gray-400">${label}</div>
    ${arr.map(f=>`<a href="javascript:openFolder(${f.id})" data-fid="${f.id}"
        class="folder-row flex items-center gap-2 px-2 py-1.5 rounded text-xs ${CUR&&CUR.id===f.id?'bg-indigo-50 text-indigo-700 font-semibold':'text-gray-700 hover:bg-gray-50'}">
      <i class="fa-solid ${f.is_personal==1?'fa-folder-user':'fa-folder'} ${f.visibility==='all'?'text-green-500':'text-amber-400'}"></i>
      <span class="flex-1 truncate">${esc(f.name)}</span>
      ${f.visibility==='all'?'<i class="fa-solid fa-globe text-[9px] text-green-500" title="shared with everyone"></i>':''}
      <span class="text-[9px] text-gray-400">${f.file_count}</span></a>`).join('')}` : '';
  const mine   = FOLDERS.filter(f=>f.is_mine);
  const shared = FOLDERS.filter(f=>!f.is_mine && f.visibility!=='all');
  const all    = FOLDERS.filter(f=>!f.is_mine && f.visibility==='all');
  document.getElementById('folderSections').innerHTML =
      sec('My folders', mine) + sec(IS_ADMIN?'All folders (admin)':'Shared with me', shared) + sec('Shared with everyone', all)
      || '<div class="text-gray-400 text-xs p-2">No folders</div>';
  if (keep && CUR) openFolder(CUR.id, true);
}

async function openFolder(id, silent){
  const res = await apiGet('documents.php', { files:id });
  if (!res.success){ toast(res.error||'Failed', false); return; }
  CUR = res.data.folder;
  const files = res.data.files || [];
  if (!silent) loadTree(false).then(()=>{ /* re-highlight */ });
  document.querySelectorAll('.folder-row').forEach(el=>{
    el.className = 'folder-row flex items-center gap-2 px-2 py-1.5 rounded text-xs ' +
      (+el.dataset.fid===+id ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-gray-700 hover:bg-gray-50');
  });
  document.getElementById('folderHead').classList.remove('hidden');
  document.getElementById('fhName').textContent = CUR.name;
  document.getElementById('fhOwner').textContent = CUR.is_mine ? 'your folder' : ('owner: ' + (CUR.owner_name||''));
  document.getElementById('fhBadges').innerHTML =
    (CUR.visibility==='all' ? badge('shared with everyone','green') : '') +
    (CUR.can_edit ? badge('can upload','blue') : badge('view only','gray'));
  document.getElementById('fhShare').classList.toggle('hidden', !(CUR.is_mine || IS_ADMIN));
  document.getElementById('fhRename').classList.toggle('hidden', !(CUR.is_mine || IS_ADMIN));
  document.getElementById('fhDelete').classList.toggle('hidden', !(CUR.is_mine || IS_ADMIN));
  document.getElementById('uploadBtn').classList.toggle('hidden', !CUR.can_edit);
  document.getElementById('fileCount').textContent = files.length + ' file(s)';
  document.getElementById('fileRows').innerHTML = files.map(f=>`
    <tr>
      <td class="w-6 text-center"><i class="fa-solid ${FICON(f.mime)}"></i></td>
      <td class="font-medium max-w-[300px] truncate" title="${esc(f.orig_name)}">
        <a class="text-indigo-600 hover:underline" href="api/sales/documents.php?download=${f.id}">${esc(f.orig_name)}</a></td>
      <td class="text-gray-500">${fmtBytes(f.size_bytes)}</td>
      <td class="text-[11px]">${esc(f.uploaded_by_name||'')}</td>
      <td class="text-[10px] text-gray-400">${esc(String(f.created_at||'').slice(0,16))}</td>
      <td class="rowact">
        <a class="text-[10px] bg-gray-100 text-gray-700 px-2 py-0.5 rounded" href="api/sales/documents.php?download=${f.id}"><i class="fa-solid fa-download"></i></a>
        <button onclick="delFile(${f.id})" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded ml-1"><i class="fa-solid fa-trash"></i></button>
      </td>
    </tr>`).join('') || `<tr><td colspan="6" class="text-center text-gray-400 py-10">
      ${CUR.can_edit?'Empty folder — drag & drop files here, or click Upload':'Empty folder'}</td></tr>`;
}

/* ---------- upload (button + drag & drop) ---------- */
async function uploadFiles(fileList){
  if (!CUR){ toast('Select a folder first', false); return; }
  if (!CUR.can_edit){ toast('You have view-only access to this folder', false); return; }
  if (!fileList || !fileList.length) return;
  const fd = new FormData();
  for (const f of fileList) fd.append('files[]', f);
  document.getElementById('upProgress').classList.remove('hidden');
  try {
    const r = await fetch('api/sales/documents.php?upload=' + CUR.id, {
      method:'POST', credentials:'same-origin', headers:{ 'X-CSRF-Token': CSRF_TOKEN }, body: fd });
    const res = await r.json();
    if (res.success){
      const d = res.data;
      toast(`Uploaded ${d.saved.length} file(s)` + (d.rejected.length?` · ${d.rejected.length} rejected`:''), d.rejected.length===0);
      if (d.rejected.length) console.warn('rejected:', d.rejected);
      openFolder(CUR.id, true); loadTree(true);
    } else toast(res.error||'Upload failed', false);
  } catch(e){ toast('Upload failed — network error', false); }
  document.getElementById('upProgress').classList.add('hidden');
  document.getElementById('fileInput').value = '';
}
const dz = document.getElementById('dropZone');
let dzTimer;
['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev, e=>{
  e.preventDefault(); if (CUR && CUR.can_edit){ document.getElementById('dzHint').classList.remove('hidden'); clearTimeout(dzTimer); }
}));
['dragleave','drop'].forEach(ev=>dz.addEventListener(ev, e=>{
  e.preventDefault(); dzTimer = setTimeout(()=>document.getElementById('dzHint').classList.add('hidden'), 80);
}));
dz.addEventListener('drop', e=>{ uploadFiles(e.dataTransfer.files); });

/* ---------- folder ops ---------- */
async function newFolder(){
  const name = prompt('Folder name:',''); if (!name) return;
  const res = await apiSend('documents.php', { name });
  if (res.success){ toast('Folder created'); loadTree(false).then(()=>openFolder(res.data.id, true)); }
  else toast(res.error||'Failed', false);
}
async function renameFolder(){
  const name = prompt('New name:', CUR.name); if (!name || name===CUR.name) return;
  const res = await apiSend('documents.php?folder='+CUR.id, { name }, 'PATCH');
  if (res.success){ toast('Renamed'); openFolder(CUR.id, true); loadTree(true); } else toast(res.error||'Failed', false);
}
async function deleteFolder(){
  if (!confirm('Delete this folder? It must be empty.')) return;
  const res = await apiSend('documents.php?folder='+CUR.id, {}, 'DELETE');
  if (res.success){ toast('Folder deleted'); CUR=null; document.getElementById('folderHead').classList.add('hidden');
    document.getElementById('fileRows').innerHTML='<tr><td colspan="6" class="text-center text-gray-400 py-10">Select a folder</td></tr>'; loadTree(false); }
  else toast(res.error||'Failed', false);
}
async function delFile(id){
  if (!confirm('Delete this file?')) return;
  const res = await apiSend('documents.php?file='+id, {}, 'DELETE');
  if (res.success){ toast('File deleted'); openFolder(CUR.id, true); loadTree(true); } else toast(res.error||'Failed', false);
}

/* ---------- sharing ---------- */
async function openShare(){
  const { data } = await apiGet('documents.php', { access: CUR.id });
  const g = data.grants||[];
  openModal(`
    <div class="text-xs space-y-3">
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Who can see this folder</div>
        <label class="flex items-center gap-2 py-0.5"><input type="radio" name="vis" value="private" ${data.folder.visibility==='private'?'checked':''}>
          <i class="fa-solid fa-lock text-gray-400 w-4"></i> Only me + people/groups below <span class="text-gray-400">(admin always has access)</span></label>
        <label class="flex items-center gap-2 py-0.5"><input type="radio" name="vis" value="all" ${data.folder.visibility==='all'?'checked':''}>
          <i class="fa-solid fa-globe text-green-500 w-4"></i> Everyone in the company</label>
      </div>
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Current grants</div>
        <div class="space-y-1" id="grantList">
          ${g.map(x=>`<div class="flex items-center gap-2 bg-gray-50 rounded px-2 py-1">
            <i class="fa-solid ${x.grantee_type==='group'?'fa-people-group text-indigo-500':'fa-user text-gray-400'}"></i>
            <span class="flex-1">${esc(x.grantee_name||('#'+x.grantee_id))}</span>
            ${+x.can_edit? badge('can upload','blue') : badge('view','gray')}
            <button onclick="revokeGrant(${x.id})" class="text-red-500 hover:text-red-700"><i class="fa-solid fa-xmark"></i></button>
          </div>`).join('') || '<div class="text-gray-400">No individual/group grants yet</div>'}
        </div>
      </div>
      <div>
        <div class="text-[10px] uppercase text-gray-400 mb-1">Add people or groups</div>
        <div class="flex gap-1.5 items-center">
          <select id="shType" class="ctl w-24" onchange="shFill()"><option value="employee">Person</option><option value="group">Group</option></select>
          <select id="shWho" class="ctl flex-1"></select>
          <label class="flex items-center gap-1 whitespace-nowrap"><input id="shEdit" type="checkbox" class="chk"> can upload</label>
          <button onclick="addGrant()" class="btn-p">Add</button>
        </div>
      </div>
      <div class="flex justify-end gap-2 pt-1">
        <button onclick="closeModal()" class="px-3 py-1.5 border rounded">Close</button>
        <button onclick="saveVisibility()" class="btn-p">Save visibility</button>
      </div>
    </div>`, 'Share — ' + data.folder.name);
  window._shData = data; shFill();
}
function shFill(){
  const t = document.getElementById('shType').value;
  const src = t==='group' ? (_shData.groups||[]) : (_shData.employees||[]);
  document.getElementById('shWho').innerHTML = src.map(x=>`<option value="${x.id}">${esc(x.name||x.full_name)}</option>`).join('');
}
async function addGrant(){
  const res = await apiSend('documents.php?share='+CUR.id, { add:[{ type:document.getElementById('shType').value,
    id:+document.getElementById('shWho').value, can_edit:document.getElementById('shEdit').checked?1:0 }] });
  if (res.success){ toast('Access granted'); openShare(); loadTree(true); } else toast(res.error||'Failed', false);
}
async function revokeGrant(id){
  const res = await apiSend('documents.php?share='+CUR.id, { remove:[id] });
  if (res.success){ toast('Access revoked'); openShare(); loadTree(true); } else toast(res.error||'Failed', false);
}
async function saveVisibility(){
  const vis = document.querySelector('input[name=vis]:checked').value;
  const res = await apiSend('documents.php?share='+CUR.id, { visibility: vis });
  if (res.success){ toast('Sharing updated'); closeModal(); openFolder(CUR.id, true); loadTree(true); } else toast(res.error||'Failed', false);
}

loadTree(false);
</script>
</html>
