<?php
/**
 * Shell for the CFO cockpit screens.
 *
 * Each of the eleven pages is: cfo_page() for the guard + chrome, cfo_head()
 * for the "what question does this screen answer" header, its own markup, then
 * cfo_scripts(). Keeping the shell here means the whole product changes look in
 * one place instead of eleven.
 */
require_once __DIR__ . '/sales-layout.php';

/** Guard + head + header + sidebar for a cockpit page. Returns the user. */
function cfo_page(string $module, string $title): array
{
    $me = sales_guard();
    sales_head($title);
    sales_header($me, $title);
    sales_nav($module);
    cfo_styles();
    return $me;
}

function cfo_styles(): void
{ ?>
<style>
  .ctile { border:1px solid #e5e7eb; border-radius:10px; padding:8px 12px; background:#fff; min-width:146px; }
  .ct-l  { font-size:10px; color:#9ca3af; white-space:nowrap; }
  .ct-n  { font-size:19px; font-weight:700; line-height:1.15; }
  .ct-s  { font-size:10px; color:#9ca3af; }
  .ccode { font-family:ui-monospace,Consolas,monospace; font-size:10px; font-weight:700;
           border-radius:5px; padding:1px 5px; white-space:nowrap; background:#eef2ff; color:#4338ca; }
  .ccode.risk { background:#fef2f2; color:#b91c1c; }
  .ccode.form { background:#ecfeff; color:#0e7490; }
  .ccode.task { background:#f5f3ff; color:#6d28d9; }
  .ccode.capa { background:#fff7ed; color:#c2410c; }
  .cband { color:#fff; font-size:10px; font-weight:600; border-radius:5px; padding:1px 6px; white-space:nowrap; }
  .crag  { color:#fff; font-size:10px; font-weight:600; border-radius:99px; padding:1px 7px; white-space:nowrap; }
  .cq    { font-size:11px; color:#6b7280; }
  .ctab  { padding:4px 12px; font-size:12px; border-radius:8px; color:#4b5563; cursor:pointer; white-space:nowrap; }
  .ctab.on { background:#2563eb; color:#fff; font-weight:600; }
  .cchip { border:1px solid #e5e7eb; border-radius:99px; padding:2px 10px; font-size:11px; cursor:pointer; background:#fff; }
  .cchip.on { background:#1f2937; color:#fff; border-color:#1f2937; }
  .heat td { text-align:center; font-size:10px; padding:0; }
  .heat .cellbox { min-height:38px; border:1px solid #fff; display:flex; align-items:center;
                   justify-content:center; flex-wrap:wrap; gap:2px; padding:3px; color:#fff; font-weight:600; }
  .bar { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
  .bar > span { display:block; height:100%; }
  table.dense th { position:sticky; top:0; background:#f9fafb; z-index:1; }
</style>
<?php }

/**
 * The header every cockpit screen carries: the title, the question the screen
 * exists to answer, and its actions.
 */
function cfo_head(string $title, string $question, string $actionsHtml = ''): void
{ ?>
<div class="flex items-start gap-3 flex-wrap">
  <div>
    <div class="text-sm font-bold text-gray-700 tracking-wide"><?= htmlspecialchars(strtoupper($title)) ?></div>
    <div class="cq"><?= htmlspecialchars($question) ?></div>
  </div>
  <div class="flex-1"></div>
  <div class="flex items-center gap-2 flex-wrap"><?= $actionsHtml ?></div>
</div>
<?php }

/** Modal container + scripts + the cockpit's shared JS. */
function cfo_scripts(): void
{ ?>
<div id="modal" class="hidden fixed inset-0 bg-black/40 z-50 items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[92vh] overflow-y-auto">
    <div class="flex items-center justify-between px-4 py-2.5 border-b">
      <h3 id="modalTitle" class="font-semibold text-sm"></h3>
      <button onclick="closeModal()" class="text-gray-400 hover:text-gray-700"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div id="modalBody" class="p-4"></div>
  </div>
</div>
<?php sales_scripts(); ?>
<script src="js/cfo.js?v=1"></script>
<?php }
