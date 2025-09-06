<?php
/*
Plugin Name: Daybreak App 2 (Safe Dashboard)
Description: Self-contained Dashboard + Deals Kanban + Lists for Daybreak CRM using admin-ajax only. No global footer hooks.
Version: 0.3.0
Author: ChatGPT
*/
if (!defined('ABSPATH')) exit;

class Daybreak_App2_Safe {
  public function __construct() {
    add_shortcode('daybreak_app2', [$this,'shortcode']);
    add_action('init', [$this,'maybe_register_activity']);

    // admin-ajax endpoints (logged-in only)
    add_action('wp_ajax_dbrk2_table',        [$this,'ajax_table']);
    add_action('wp_ajax_dbrk2_stages',       [$this,'ajax_stages']);
    add_action('wp_ajax_dbrk2_stage',        [$this,'ajax_stage']);

    add_action('wp_ajax_dbrk2_quick_add',    [$this,'ajax_quick_add']);

    add_action('wp_ajax_dbrk2_task_add',     [$this,'ajax_task_add']);
    add_action('wp_ajax_dbrk2_task_toggle',  [$this,'ajax_task_toggle']);

    add_action('wp_ajax_dbrk2_scratch_get',  [$this,'ajax_scratch_get']);
    add_action('wp_ajax_dbrk2_scratch_set',  [$this,'ajax_scratch_set']);
    add_action('wp_ajax_dbrk2_note_add',     [$this,'ajax_note_add']);
    add_action('wp_ajax_dbrk2_notes_recent', [$this,'ajax_notes_recent']);
  }

  public function maybe_register_activity(){
    if (!post_type_exists('dbrk_activity')) {
      register_post_type('dbrk_activity', [
        'label'     => 'Activities',
        'public'    => false,
        'show_ui'   => false,
        'supports'  => ['title','editor','author','custom-fields'],
      ]);
    }
  }

  private function pt($t){
    $m=['contacts'=>'dbrk_contact','companies'=>'dbrk_company','properties'=>'dbrk_property','deals'=>'dbrk_deal','tasks'=>'dbrk_task'];
    return $m[$t]??'';
  }

  /* ------------ AJAX ------------ */

  public function ajax_table(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $type=sanitize_key($_GET['type']??''); $pt=$this->pt($type);
    if(!$pt) wp_send_json_success(['items'=>[]],200);
    $per=max(1,min(200,intval($_GET['per_page']??50)));
    $page=max(1,intval($_GET['page']??1));
    $s=sanitize_text_field($_GET['q']??'');
    $q=new WP_Query([
      'post_type'=>$pt,'post_status'=>'publish','s'=>$s,
      'posts_per_page'=>$per,'paged'=>$page,'orderby'=>'date','order'=>'DESC','no_found_rows'=>true
    ]);
    $items=[];
    foreach($q->posts as $p){
      $row=['id'=>$p->ID,'title'=>get_the_title($p)];
      if($type==='deals'){ $st=wp_get_post_terms($p->ID,'dbrk_stage',['fields'=>'names']); $row['stage']=$st?$st[0]:null; }
      if($type==='tasks'){ $row['done']=(bool)get_post_meta($p->ID,'dbrk_done',true); $row['due_at']=get_post_meta($p->ID,'dbrk_due_at',true); }
      $items[]=$row;
    }
    wp_send_json_success(['items'=>$items],200);
  }

  public function ajax_stages(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $out=[];
    if (taxonomy_exists('dbrk_stage')) {
      $terms=get_terms(['taxonomy'=>'dbrk_stage','hide_empty'=>false]);
      if(!is_wp_error($terms)){
        foreach($terms as $t) $out[]=$t->name;
      }
    }
    if(!$out){ // fallback default ladder
      $out=['Prospect','Qualification','Tour','Negotiation','Under LOI','Under Contract','Closed'];
    }
    wp_send_json_success(['stages'=>$out],200);
  }

  public function ajax_stage(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $id=intval($_GET['id']??0); $stage=sanitize_text_field($_GET['stage']??'');
    if(!$id||get_post_type($id)!=='dbrk_deal'||!$stage) wp_send_json_error(['error'=>'bad_request'],400);
    $t=get_term_by('name',$stage,'dbrk_stage');
    if(!$t) $t=get_term_by('slug',sanitize_title($stage),'dbrk_stage');
    if(!$t){
      $make=wp_insert_term($stage,'dbrk_stage');
      if(is_wp_error($make)) wp_send_json_error(['error'=>'stage'],400);
      $t=get_term($make['term_id'],'dbrk_stage');
    }
    wp_set_object_terms($id,[intval($t->term_id)],'dbrk_stage',false);
    wp_send_json_success(['ok'=>true],200);
  }

  public function ajax_quick_add(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $type=sanitize_key($_POST['type']??'');
    $title=sanitize_text_field($_POST['title']??'');
    $pt=$this->pt($type);
    if(!$pt || !$title) wp_send_json_error(['error'=>'bad_request'],400);
    $id=wp_insert_post(['post_type'=>$pt,'post_status'=>'publish','post_title'=>$title]);
    if(!$id||is_wp_error($id)) wp_send_json_error(['error'=>'create_failed'],500);
    wp_send_json_success(['id'=>$id,'title'=>$title],200);
  }

  public function ajax_task_add(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $t=sanitize_text_field($_POST['title']??''); $due=sanitize_text_field($_POST['due_at']??'');
    if(!$t) wp_send_json_error(['error'=>'bad_request'],400);
    $id=wp_insert_post(['post_type'=>'dbrk_task','post_status'=>'publish','post_title'=>$t]);
    if($due) update_post_meta($id,'dbrk_due_at',$due);
    wp_send_json_success(['id'=>$id],200);
  }

  public function ajax_task_toggle(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $id=intval($_GET['id']??0);
    if(!$id||get_post_type($id)!=='dbrk_task') wp_send_json_error(['error'=>'not_found'],404);
    $new=(bool)get_post_meta($id,'dbrk_done',true)?0:1;
    update_post_meta($id,'dbrk_done',$new);
    wp_send_json_success(['id'=>$id,'done'=>(bool)$new],200);
  }

  public function ajax_scratch_get(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    wp_send_json_success(['text'=>get_user_meta(get_current_user_id(),'dbrk2_scratch',true)?:''],200);
  }
  public function ajax_scratch_set(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $text=wp_kses_post($_POST['text']??'');
    update_user_meta(get_current_user_id(),'dbrk2_scratch',$text);
    wp_send_json_success(['saved'=>true],200);
  }

  public function ajax_note_add(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $text=wp_kses_post($_POST['text']??'');
    if(!$text) wp_send_json_error(['error'=>'bad_request'],400);
    $title=wp_trim_words(wp_strip_all_tags($text),10,'…');
    $id=wp_insert_post([
      'post_type'=>'dbrk_activity','post_status'=>'publish',
      'post_title'=>$title?:'Note','post_content'=>$text,'post_author'=>get_current_user_id()
    ]);
    if(!$id||is_wp_error($id)) wp_send_json_error(['error'=>'create_failed'],500);
    $lt=sanitize_key($_POST['link_type']??''); $lid=intval($_POST['link_id']??0);
    if(in_array($lt,['contacts','companies','properties','deals'],true) && $lid){
      update_post_meta($id,'dbrk_link_key',$lt.':'.$lid);
    }
    wp_send_json_success(['id'=>$id],200);
  }

  public function ajax_notes_recent(){
    if(!is_user_logged_in()) wp_send_json_error(['error'=>'forbidden'],403);
    $q=new WP_Query([
      'post_type'=>'dbrk_activity','post_status'=>'publish','posts_per_page'=>10,
      'orderby'=>'date','order'=>'DESC','no_found_rows'=>true
    ]);
    $items=[];
    foreach($q->posts as $p){
      $items[]=[
        'id'=>$p->ID,'title'=>get_the_title($p),
        'excerpt'=>wp_trim_words(wp_strip_all_tags($p->post_content),24,'…'),
        'date'=>get_the_date('', $p)
      ];
    }
    wp_send_json_success(['items'=>$items],200);
  }

  /* ------------ Shortcode UI ------------ */

  public function shortcode($atts){
    ob_start(); ?>
    <style>
      .dbrk2-shell{margin:16px;background:#fff;border:1px solid #eee;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
      .dbrk2-top{display:flex;gap:8px;align-items:center;padding:10px;border-bottom:1px solid #eee;background:#fff;border-radius:12px 12px 0 0}
      .dbrk2-top input{flex:1;padding:8px 10px;border:1px solid #e1e1e1;border-radius:8px}
      .dbrk2-btn{all:unset;cursor:pointer;padding:8px 12px;border:1px solid #e1e1e1;border-radius:8px}
      .dbrk2-tabs{display:flex;gap:8px;padding:10px;border-bottom:1px solid #eee;background:#fafafa}
      .dbrk2-tab{all:unset;cursor:pointer;padding:8px 12px;border:1px solid #e1e1e1;border-radius:8px;font:14px system-ui}
      .dbrk2-tab.active{background:#f5f7ff;border-color:#cbd5ff}
      .dbrk2-body{padding:12px}

      .dbrk2-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}
      .dbrk2-card{background:#fafbff;border:1px solid #e7eaff;border-radius:10px}
      .dbrk2-card h4{margin:0;padding:10px 12px;border-bottom:1px solid #eaefff;font:600 13px system-ui}
      .dbrk2-box{padding:10px}
      .dbrk2-row{display:flex;gap:8px;align-items:center;margin:6px 0}
      .dbrk2-input{padding:8px 10px;border:1px solid #e1e1e1;border-radius:8px}

      /* Kanban */
      .dbrk2-grid-kan{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
      .dbrk2-col{background:#fafbff;border:1px solid #e7eaff;border-radius:10px;display:flex;flex-direction:column}
      .dbrk2-col h4{margin:0;padding:10px 12px;border-bottom:1px solid #eaefff;font:600 13px system-ui}
      .dbrk2-lane{flex:1;overflow:auto;padding:10px;display:flex;flex-direction:column;gap:8px;min-height:120px}
      .dbrk2-cardd{background:#fff;border:1px solid #ececec;border-radius:8px;padding:8px 10px;box-shadow:0 4px 16px rgba(0,0,0,.08);cursor:grab}

      table.dbrk2-table{width:100%;border-collapse:collapse}
      table.dbrk2-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0}
    </style>

    <div id="dbrk2-app" class="dbrk2-shell">
      <div class="dbrk2-top">
        <input id="dbrk2-gs" placeholder="Search contacts, companies, properties, deals…">
        <button id="dbrk2-gsbtn" class="dbrk2-btn">Search</button>
      </div>
      <div class="dbrk2-tabs">
        <button class="dbrk2-tab active" data-tab="dash">Dashboard</button>
        <button class="dbrk2-tab" data-tab="deals">Deals</button>
        <button class="dbrk2-tab" data-tab="lists">Lists</button>
      </div>
      <div class="dbrk2-body">
        <div id="tab-dash"></div>
        <div id="tab-deals" style="display:none"></div>
        <div id="tab-lists" style="display:none"></div>
      </div>
    </div>

    <script>
    (function(){
      const ajax=(action,params,method='GET',body=null)=>{
        const url='/wp-admin/admin-ajax.php?'+new URLSearchParams({action,...(method==='GET'?(params||{}):{})});
        return fetch(url, method==='GET'?{}:{method, body});
      };

      function switchTab(name){
        document.querySelectorAll('.dbrk2-tab').forEach(b=>b.classList.toggle('active', b.dataset.tab===name));
        ['dash','deals','lists'].forEach(t=>{ document.getElementById('tab-'+t).style.display=(t===name)?'block':'none'; });
      }

      /* ------- Dashboard ------- */
      async function loadDashboard(){
        const host=document.getElementById('tab-dash'); host.innerHTML='';
        const grid=document.createElement('div'); grid.className='dbrk2-grid'; host.appendChild(grid);

        // Scratchpad
        const c1=document.createElement('div'); c1.className='dbrk2-card'; c1.innerHTML='<h4>Scratchpad</h4>'; const b1=document.createElement('div'); b1.className='dbrk2-box'; c1.appendChild(b1);
        const ta=document.createElement('textarea'); ta.style.width='100%'; ta.style.minHeight='140px'; b1.appendChild(ta); grid.appendChild(c1);
        (async()=>{ const r=await ajax('dbrk2_scratch_get'); if(r.ok){ const j=await r.json(); ta.value=(j.data&&j.data.text)||j.text||''; } })();
        let tmt=null; ta.addEventListener('input',()=>{ clearTimeout(tmt); tmt=setTimeout(async()=>{ const fd=new FormData(); fd.append('action','dbrk2_scratch_set'); fd.append('text',ta.value); await fetch('/wp-admin/admin-ajax.php',{method:'POST',body:fd}); },600); });

        // Quick Log
        const c2=document.createElement('div'); c2.className='dbrk2-card'; c2.innerHTML='<h4>Quick Log Activity</h4>'; const b2=document.createElement('div'); b2.className='dbrk2-box'; c2.appendChild(b2); grid.appendChild(c2);
        const row2=document.createElement('div'); row2.className='dbrk2-row'; b2.appendChild(row2);
        const typeSel=document.createElement('select'); typeSel.className='dbrk2-input'; ['contacts','companies','properties','deals'].forEach(t=>{ const o=document.createElement('option'); o.value=t; o.textContent=t[0].toUpperCase()+t.slice(1); typeSel.appendChild(o); });
        const find=document.createElement('input'); find.className='dbrk2-input'; find.placeholder='Search record…'; find.type='search';
        const findBtn=document.createElement('button'); findBtn.className='dbrk2-btn'; findBtn.textContent='Find';
        const pick=document.createElement('select'); pick.className='dbrk2-input'; pick.style.minWidth='200px';
        row2.appendChild(typeSel); row2.appendChild(find); row2.appendChild(findBtn); row2.appendChild(pick);
        const ta2=document.createElement('textarea'); ta2.style.width='100%'; ta2.style.minHeight='100px'; ta2.placeholder='Write a quick note…';
        const log=document.createElement('button'); log.className='dbrk2-btn'; log.textContent='Add Note'; log.style.marginTop='8px';
        b2.appendChild(ta2); b2.appendChild(log);
        async function searchPick(){ const r=await ajax('dbrk2_table',{type:typeSel.value,per_page:20,q:find.value||''}); const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[]; pick.innerHTML=''; items.forEach(x=>{ const o=document.createElement('option'); o.value=x.id; o.textContent=x.title||('#'+x.id); pick.appendChild(o); }); }
        findBtn.onclick=searchPick; find.addEventListener('keydown',e=>{ if(e.key==='Enter') searchPick(); });
        log.onclick=async()=>{ const text=(ta2.value||'').trim(); if(!text){ alert('Write a note.'); return; } const fd=new FormData(); fd.append('action','dbrk2_note_add'); fd.append('text',text); if(pick.value){ fd.append('link_type',typeSel.value); fd.append('link_id',pick.value); } await fetch('/wp-admin/admin-ajax.php',{method:'POST',body:fd}); ta2.value=''; loadRecent(); };

        // Tasks mini
        const c3=document.createElement('div'); c3.className='dbrk2-card'; c3.innerHTML='<h4>Tasks (Today & Next 7)</h4>'; const b3=document.createElement('div'); b3.className='dbrk2-box'; c3.appendChild(b3); grid.appendChild(c3);
        async function loadTasksMini(){ const r=await ajax('dbrk2_table',{type:'tasks',per_page:100}); const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[];
          const today=new Date(); today.setHours(0,0,0,0); const in7=new Date(today); in7.setDate(in7.getDate()+7);
          const tToday=[], tNext=[]; items.forEach(x=>{ if(!x.due_at) return; const d=new Date(x.due_at.replace(' ','T')); if(isNaN(d)) return; const dd=new Date(d); dd.setHours(0,0,0,0); if(dd.getTime()===today.getTime()) tToday.push(x); else if(dd>today && dd<=in7) tNext.push(x); });
          b3.innerHTML=''; const sec=(label,arr,empty)=>{ const box=document.createElement('div'); box.innerHTML=`<div class="dbrk2-muted" style="margin-bottom:6px">${label}</div>`; (arr.length?arr:[{title:empty}]).forEach(x=>{ const li=document.createElement('div'); li.textContent=x.title+(x.due_at?' – '+x.due_at:''); box.appendChild(li); }); b3.appendChild(box); }; sec('Today',tToday,'No tasks today.'); sec('Next 7 days',tNext,'No upcoming tasks.'); }
        loadTasksMini();

        // Active deals
        const c4=document.createElement('div'); c4.className='dbrk2-card'; c4.innerHTML='<h4>Active Deals</h4>'; const b4=document.createElement('div'); b4.className='dbrk2-box'; c4.appendChild(b4); grid.appendChild(c4);
        async function loadActiveDeals(){ const r=await ajax('dbrk2_table',{type:'deals',per_page:50}); const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[]; const active=items.filter(x=>(x.stage||'').toLowerCase()!=='closed').slice(0,10); b4.innerHTML=''; (active.length?active:[{title:'No active deals.'}]).forEach(d=>{ const row=document.createElement('div'); row.textContent=d.title+(d.stage?' • '+d.stage:''); b4.appendChild(row); }); }
        loadActiveDeals();

        // Recent notes
        const c5=document.createElement('div'); c5.className='dbrk2-card'; c5.innerHTML='<h4>Recent Notes</h4>'; const b5=document.createElement('div'); b5.className='dbrk2-box'; c5.appendChild(b5); grid.appendChild(c5);
        async function loadRecent(){ const r=await ajax('dbrk2_notes_recent'); const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[]; b5.innerHTML=''; if(!items.length){ b5.textContent='No notes yet.'; return; } items.forEach(n=>{ const row=document.createElement('div'); row.innerHTML=`<div style="font:600 13px system-ui">${n.title}</div><div class="dbrk2-muted" style="margin:2px 0 6px">${n.date}</div><div>${n.excerpt}</div>`; b5.appendChild(row); }); }
        loadRecent();
      }

      /* ------- Deals Kanban ------- */
      async function loadDeals(){
        const host=document.getElementById('tab-deals'); host.innerHTML='';
        const stagesRes=await ajax('dbrk2_stages'); const sj=stagesRes.ok?await stagesRes.json():{data:{stages:[]}};
        const stages=(sj.data&&sj.data.stages)||sj.stages||['Prospect','Qualification','Tour','Negotiation','Under LOI','Under Contract','Closed'];
        const r=await ajax('dbrk2_table',{type:'deals',per_page:200}); const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[];

        const grid=document.createElement('div'); grid.className='dbrk2-grid-kan'; host.appendChild(grid);
        const lanes={}; stages.forEach(s=>{ const col=document.createElement('div'); col.className='dbrk2-col'; col.innerHTML=`<h4>${s}</h4>`; const lane=document.createElement('div'); lane.className='dbrk2-lane'; lane.dataset.stage=s; col.appendChild(lane); grid.appendChild(col); lanes[s]=lane; });

        items.forEach(d=>{ const s=(d.stage||'').toLowerCase(); const tgt=stages.find(x=>x.toLowerCase()===s) || stages[0]; const lane=lanes[tgt]; const card=document.createElement('div'); card.className='dbrk2-cardd'; card.textContent=d.title; card.draggable=true; card.dataset.id=d.id; (lane||lanes[stages[0]]).appendChild(card); });

        let drag=null;
        host.addEventListener('dragstart',e=>{ const c=e.target.closest('.dbrk2-cardd'); if(!c) return; drag=c; });
        host.addEventListener('dragover',e=>{ if(e.target.closest('.dbrk2-lane')) e.preventDefault(); });
        host.addEventListener('drop',async e=>{ const lane=e.target.closest('.dbrk2-lane'); if(!lane||!drag) return; e.preventDefault(); lane.appendChild(drag); const res=await ajax('dbrk2_stage',{id:drag.dataset.id,stage:lane.dataset.stage}); alert(res.ok?'Stage updated ✅':'Save failed ❌'); });
      }

      /* ------- Lists ------- */
      async function loadLists(){
        const host=document.getElementById('tab-lists'); host.innerHTML='';
        const bar=document.createElement('div'); bar.className='dbrk2-row'; host.appendChild(bar);
        const sel=document.createElement('select'); sel.className='dbrk2-input'; [['contacts','Contacts'],['companies','Companies'],['properties','Properties'],['deals','Deals'],['tasks','Tasks']].forEach(([v,l])=>{ const o=document.createElement('option'); o.value=v; o.textContent=l; sel.appendChild(o); });
        const q=document.createElement('input'); q.className='dbrk2-input'; q.placeholder='Search…'; q.type='search';
        const loadBtn=document.createElement('button'); loadBtn.className='dbrk2-btn'; loadBtn.textContent='Load';
        bar.appendChild(sel); bar.appendChild(q); bar.appendChild(loadBtn);

        // Quick Add (except tasks)
        const qa=document.createElement('div'); qa.className='dbrk2-row'; host.appendChild(qa);
        const ti=document.createElement('input'); ti.className='dbrk2-input'; ti.style.flex='1'; ti.placeholder='New item title…';
        const add=document.createElement('button'); add.className='dbrk2-btn'; add.textContent='Add';
        qa.appendChild(ti); qa.appendChild(add);

        const out=document.createElement('div'); host.appendChild(out);

        async function render(){
          out.innerHTML='';
          const r=await ajax('dbrk2_table',{type:sel.value,per_page:100,q:q.value||''}); const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[];
          const table=document.createElement('table'); table.className='dbrk2-table'; const tb=document.createElement('tbody');
          if(!items.length){ const tr=document.createElement('tr'); const td=document.createElement('td'); td.textContent='No items found.'; tr.appendChild(td); tb.appendChild(tr); }
          items.forEach(it=>{
            const tr=document.createElement('tr');
            const td1=document.createElement('td'); td1.textContent=it.title;
            const td2=document.createElement('td'); td2.textContent=(it.stage||it.due_at||'');
            tr.appendChild(td1); tr.appendChild(td2);
            if(sel.value==='tasks'){
              const td3=document.createElement('td');
              const btn=document.createElement('button'); btn.className='dbrk2-btn'; btn.textContent=it.done?'Undone':'Done';
              btn.onclick=async()=>{ await ajax('dbrk2_task_toggle',{id:it.id}); render(); };
              td3.appendChild(btn); tr.appendChild(td3);
            }
            tb.appendChild(tr);
          });
          table.appendChild(tb); out.appendChild(table);
          qa.style.display = (sel.value==='tasks')?'none':'flex';
        }

        loadBtn.onclick=render;
        q.addEventListener('keydown',e=>{ if(e.key==='Enter') render(); });
        sel.onchange=render;

        add.onclick=async()=>{
          if(sel.value==='tasks'){ alert('Add tasks in the Tasks box on Dashboard for now.'); return; }
          const title=(ti.value||'').trim(); if(!title) return;
          const fd=new FormData(); fd.append('action','dbrk2_quick_add'); fd.append('type', sel.value); fd.append('title', title);
          await fetch('/wp-admin/admin-ajax.php',{method:'POST',body:fd});
          ti.value=''; render();
        };

        render();
      }

      /* ------- Global Search (top) ------- */
      async function globalSearch(term){
        const out=document.createElement('div'); out.className='dbrk2-box'; out.style.paddingLeft='0';
        const types=[['contacts','Contacts'],['companies','Companies'],['properties','Properties'],['deals','Deals']];
        for(const [t,label] of types){
          const r=await ajax('dbrk2_table',{type:t,per_page:10,q:term});
          const j=r.ok?await r.json():{data:{items:[]}}; const items=(j.data&&j.data.items)||j.items||[];
          if(!items.length) continue;
          const h=document.createElement('div'); h.innerHTML=`<div style="font:600 13px system-ui">${label}</div>`; out.appendChild(h);
          items.forEach(x=>{ const row=document.createElement('div'); row.textContent=x.title+(x.stage?' • '+x.stage:''); out.appendChild(row); });
        }
        // Simple modal
        const ov=document.createElement('div'); Object.assign(ov.style,{position:'fixed',inset:'0',background:'rgba(0,0,0,.2)',zIndex:'99999'});
        ov.addEventListener('click',e=>{ if(e.target===ov) ov.remove(); });
        const box=document.createElement('div'); Object.assign(box.style,{position:'absolute',left:'6vw',right:'6vw',top:'10vh',bottom:'10vh',background:'#fff',border:'1px solid #eee',borderRadius:'12px',overflow:'auto',padding:'12px'});
        const bar=document.createElement('div'); bar.style.display='flex'; bar.style.justifyContent='space-between'; bar.style.alignItems='center'; bar.style.marginBottom='8px';
        bar.innerHTML='<div style="font:600 14px system-ui">Search results</div>';
        const x=document.createElement('button'); x.textContent='×'; x.className='dbrk2-btn'; x.onclick=()=>ov.remove(); bar.appendChild(x);
        box.appendChild(bar); box.appendChild(out); ov.appendChild(box); document.body.appendChild(ov);
      }

      // Wire tabs
      document.querySelectorAll('.dbrk2-tab').forEach(b=>{
        b.addEventListener('click',()=>{ const name=b.dataset.tab; switchTab(name);
          if(name==='dash')  loadDashboard();
          if(name==='deals') loadDeals();
          if(name==='lists') loadLists();
        });
      });

      // Default tab
      loadDashboard();

      // Search bar
      const gs=document.getElementById('dbrk2-gs'); const gsBtn=document.getElementById('dbrk2-gsbtn');
      gsBtn.onclick=()=>{ const term=(gs.value||'').trim(); if(term) globalSearch(term); };
      gs.addEventListener('keydown',e=>{ if(e.key==='Enter'){ const term=(gs.value||'').trim(); if(term) globalSearch(term); }});
    })();
    </script>
    <?php
    return ob_get_clean();
  }
}
new Daybreak_App2_Safe();
