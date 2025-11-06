<?php
/****************************************************
 * GYM COACH — index.php (single file)
 * PHP 7+ — PDO MySQL — Minimal UI — Mobile-first
 ****************************************************/
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ====== CONFIG BD ======
const DB_HOST = '127.0.0.1';
const DB_NAME = 'gymcoach';
const DB_USER = 'root';
const DB_PASS = 'rootroot'; // <-- Tu password

// ====== CONEXIÓN PDO ======
function pdo() {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}

// ====== SESIÓN ======
session_start();

// ====== UTIL ======
function post($k,$d=null){return isset($_POST[$k])?$_POST[$k]:$d;}
function getv($k,$d=null){return isset($_GET[$k])?$_GET[$k]:$d;}
function is_logged(){return isset($_SESSION['uid']);}
function uid(){return is_logged()?$_SESSION['uid']:null;}
function esc($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function today(){return (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');}
function go($params=[]){$b=strtok($_SERVER["REQUEST_URI"],'?');$q=http_build_query($params);header("Location: ".$b.($q?"?$q":""));exit;}

// ====== HELPERS ======
function parse_reps($s){
  $parts = explode(',', (string)$s);
  $arr = [];
  foreach ($parts as $p) { $t = trim($p); if ($t!=='') $arr[] = (int)$t; }
  return $arr;
}
function round_to_2_5($kg){
  $r = round($kg / 2.5) * 2.5;
  return max(2.5, $r); // nunca menos de 2.5 kg
}

function inc_2_5($kg){
  return round_to_2_5($kg + 2.5);
}

function dec_2_5($kg){
  return max(2.5, round_to_2_5($kg - 2.5));
}


// ====== AUTENTICACIÓN ======
if (post('action')==='register') {
  $username = trim((string)post('username'));
  $pin = (string)post('pin');
  $pin2 = (string)post('pin2');
  if ($username===''||$pin===''||$pin2===''){ $error='Rellena todos los campos.'; }
  elseif(!preg_match('/^[a-zA-Z0-9_]{3,20}$/',$username)){ $error='Usuario 3-20 chars, letras/números/_'; }
  elseif(!preg_match('/^[0-9]{4,8}$/',$pin)){ $error='PIN numérico (4-8 dígitos).'; }
  elseif($pin!==$pin2){ $error='Las contraseñas no coinciden.'; }
  else{
    $hash = password_hash($pin, PASSWORD_DEFAULT);
    try{
      $st=pdo()->prepare("INSERT INTO users (username,pin_hash) VALUES (?,?)");
      $st->execute([$username,$hash]);
      $_SESSION['uid']=(int)pdo()->lastInsertId();
      $_SESSION['uname']=$username;
      go();
    }catch(PDOException $e){
      $error = strpos($e->getMessage(),'Duplicate entry')!==false ? 'Ese usuario ya existe.' : 'Error al registrar.';
    }
  }
}
if (post('action')==='login') {
  $username = trim((string)post('username'));
  $pin = (string)post('pin');
  $st=pdo()->prepare("SELECT id,pin_hash FROM users WHERE username=?");
  $st->execute([$username]);
  $u=$st->fetch();
  if($u && password_verify($pin,$u['pin_hash'])){
    $_SESSION['uid']=(int)$u['id'];
    $_SESSION['uname']=$username;
    go();
  } else { $error='Usuario o PIN incorrectos.'; }
}
if (getv('logout')) { session_destroy(); go(); }

// ====== DATA HELPERS ======
function user_routines(){
  $st=pdo()->prepare("SELECT * FROM routines WHERE user_id=? ORDER BY id DESC");
  $st->execute([uid()]);
  return $st->fetchAll();
}
function routine_with_days($rid){
  $st=pdo()->prepare("SELECT * FROM routines WHERE id=? AND user_id=?");
  $st->execute([$rid,uid()]);
  $r=$st->fetch();
  if(!$r) return null;
  $d=pdo()->prepare("SELECT * FROM routine_days WHERE routine_id=? ORDER BY day_index ASC");
  $d->execute([$rid]);
  $r['days_rows']=$d->fetchAll();
  return $r;
}
function day_detail($day_id){
  $st=pdo()->prepare("
    SELECT d.*, r.user_id, r.name AS routine_name
    FROM routine_days d
    JOIN routines r ON r.id=d.routine_id
    WHERE d.id=? AND r.user_id=?
  ");
  $st->execute([$day_id,uid()]);
  $d=$st->fetch();
  if(!$d) return null;

  $ex=pdo()->prepare("SELECT * FROM day_exercises WHERE day_id=? ORDER BY id ASC");
  $ex->execute([$day_id]);
  $d['exercises']=$ex->fetchAll();

  // checks de hoy
  $dt=today();
  $chk=pdo()->prepare("SELECT day_exercise_id, checked FROM exercise_checks WHERE user_id=? AND check_date=? AND day_exercise_id IN (SELECT id FROM day_exercises WHERE day_id=?)");
  $chk->execute([uid(),$dt,$day_id]);
  $map=[];
  foreach($chk as $c){ $map[(int)$c['day_exercise_id']]=(int)$c['checked']; }
  $d['checks_today']=$map;

  return $d;
}

// ====== CRUD BÁSICO (rutin/día/ejerc) ======
if (is_logged()) {
  $act = post('action') ? post('action') : getv('action');

  // Crear rutina
  if ($act==='create_routine'){
    $name=trim((string)post('routine_name')); if($name==='') $name='Mi rutina';
    $days=max(1,(int)post('routine_days'));
    pdo()->beginTransaction();
    try{
      $st=pdo()->prepare("INSERT INTO routines (user_id,name,days) VALUES (?,?,?)");
      $st->execute([uid(),$name,$days]);
      $rid=(int)pdo()->lastInsertId();
      $ins=pdo()->prepare("INSERT INTO routine_days (routine_id, day_index, day_name) VALUES (?,?,?)");
      for($i=1;$i<=$days;$i++){ $ins->execute([$rid,$i,"Día $i"]); }
      pdo()->commit();
      go(['view'=>'routine','rid'=>$rid]);
    }catch(Exception $e){ pdo()->rollBack(); $error='No se pudo crear la rutina.'; }
  }

  // Renombrar rutina
  if ($act==='rename_routine'){
    $rid=(int)post('rid'); $name=trim((string)post('routine_name'));
    $st=pdo()->prepare("UPDATE routines SET name=? WHERE id=? AND user_id=?");
    $st->execute([$name,$rid,uid()]);
    go(['view'=>'routine','rid'=>$rid]);
  }

  // Eliminar rutina
  if ($act==='delete_routine'){
    $rid=(int)post('rid');
    $st=pdo()->prepare("DELETE FROM routines WHERE id=? AND user_id=?");
    $st->execute([$rid,uid()]);
    go(['view'=>'home']);
  }

  // Renombrar día
  if ($act==='rename_day'){
    $day_id=(int)post('day_id'); $day_name=trim((string)post('day_name'));
    $st=pdo()->prepare("
      UPDATE routine_days d
      JOIN routines r ON r.id=d.routine_id
      SET d.day_name=?
      WHERE d.id=? AND r.user_id=?
    ");
    $st->execute([$day_name,$day_id,uid()]);
    go(['view'=>'day','day_id'=>$day_id,'edit'=>1]);
  }

  // Añadir ejercicio
  if ($act==='add_ex'){
    $day_id=(int)post('day_id'); $name=trim((string)post('exercise_name'));
    $series=max(1,(int)post('series')); $rmin=max(1,(int)post('reps_min')); $rmax=max($rmin,(int)post('reps_max'));
    $notes=trim((string)post('notes'));
    $st=pdo()->prepare("
      INSERT INTO day_exercises (day_id, exercise_name, series, reps_min, reps_max, notes, next_target_series, next_target_reps)
      SELECT ?,?,?,?,?,?,?,?
      WHERE EXISTS (SELECT 1 FROM routine_days d JOIN routines r ON r.id=d.routine_id WHERE d.id=? AND r.user_id=?)
    ");
    // de inicio, el objetivo = series y reps_min; peso objetivo nulo hasta primer log
    $st->execute([$day_id,$name,$series,$rmin,$rmax,$notes,$series,$rmin,$day_id,uid()]);
    go(['view'=>'day','day_id'=>$day_id,'edit'=>1]);
  }

  // Editar ejercicio
  if ($act==='edit_ex'){
    $ex_id=(int)post('ex_id'); $day_id=(int)post('day_id');
    $name=trim((string)post('exercise_name'));
    $series=max(1,(int)post('series')); $rmin=max(1,(int)post('reps_min')); $rmax=max($rmin,(int)post('reps_max'));
    $notes=trim((string)post('notes'));
    $st=pdo()->prepare("
      UPDATE day_exercises e
      JOIN routine_days d ON d.id=e.day_id
      JOIN routines r ON r.id=d.routine_id
      SET e.exercise_name=?, e.series=?, e.reps_min=?, e.reps_max=?, e.notes=?
      WHERE e.id=? AND r.user_id=?
    ");
    $st->execute([$name,$series,$rmin,$rmax,$notes,$ex_id,uid()]);
    go(['view'=>'day','day_id'=>$day_id,'edit'=>1]);
  }

  // Eliminar ejercicio
  if ($act==='del_ex'){
    $ex_id=(int)post('ex_id'); $day_id=(int)post('day_id');
    $st=pdo()->prepare("
      DELETE e FROM day_exercises e
      JOIN routine_days d ON d.id=e.day_id
      JOIN routines r ON r.id=d.routine_id
      WHERE e.id=? AND r.user_id=?
    ");
    $st->execute([$ex_id,uid()]);
    go(['view'=>'day','day_id'=>$day_id,'edit'=>1]);
  }

  // Toggle check (hoy)
  if ($act==='toggle_check'){
    $ex_id=(int)post('ex_id'); $day_id=(int)post('day_id'); $dt=today();
    $sel=pdo()->prepare("SELECT checked FROM exercise_checks WHERE user_id=? AND day_exercise_id=? AND check_date=?");
    $sel->execute([uid(),$ex_id,$dt]); $row=$sel->fetch();
    if($row){
      $new=$row['checked']?0:1;
      $upd=pdo()->prepare("UPDATE exercise_checks SET checked=? WHERE user_id=? AND day_exercise_id=? AND check_date=?");
      $upd->execute([$new,uid(),$ex_id,$dt]);
    } else {
      $ins=pdo()->prepare("INSERT INTO exercise_checks (user_id,day_exercise_id,check_date,checked) VALUES (?,?,?,1)");
      $ins->execute([uid(),$ex_id,$dt]);
    }
    go(['view'=>'day','day_id'=>$day_id]);
  }

  // Limpiar checks de hoy
  if ($act==='clear_checks'){
    $day_id=(int)post('day_id'); $dt=today();
    $st=pdo()->prepare("
      DELETE c FROM exercise_checks c
      JOIN day_exercises e ON e.id=c.day_exercise_id
      JOIN routine_days d ON d.id=e.day_id
      JOIN routines r ON r.id=d.routine_id
      WHERE c.user_id=? AND c.check_date=? AND d.id=?
    ");
    $st->execute([uid(),$dt,$day_id]);
    go(['view'=>'day','day_id'=>$day_id]);
  }

  // Guardar log (fecha real) + ACTUALIZAR OBJETIVO
  if ($act==='save_log'){
    $rid = (int)post('rid') ?: null;
    $day_id = (int)post('day_id') ?: null;
    $exercise_name = trim((string)post('exercise_name'));
    $session_date = post('session_date') ? (string)post('session_date') : today();
    $weight = round_to_2_5((float)post('weight_kg')); // fuerza múltiplo de 2.5
    $series = max(1,(int)post('series'));
    $reps_str = preg_replace('/\s+/', '', $_POST['reps_per_set']);
    $rpe = post('rpe')!=='' ? max(1,min(10,(int)post('rpe'))) : null;
    $obs = trim((string)post('observations')) ?: null;

    $dt = DateTime::createFromFormat('Y-m-d', $session_date, new DateTimeZone('Europe/Madrid'));
    $week_number = (int)$dt->format('oW');


    // Insertar log
    $st=pdo()->prepare("INSERT INTO exercise_logs (user_id, routine_id, day_id, exercise_name, week_number, weight_kg, series, reps_per_set, rpe, observations, session_date)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([uid(),$rid,$day_id,$exercise_name,$week_number,$weight,$series,$reps_str,$rpe,$obs,$session_date]);

    // === Recalcular objetivo para ese ejercicio (en este día) y guardarlo ===
    update_next_target_for_day_exercise($day_id, $exercise_name);

    go(['view'=>'day','day_id'=>$day_id]);
  }

  // EDITAR / BORRAR LOG
  if ($act==='update_log'){
    $log_id=(int)post('log_id');
    $day_id=(int)post('day_id');
    $session_date=(string)post('session_date');
    $weight=round_to_2_5((float)post('weight_kg'));
    $series=max(1,(int)post('series'));
    $reps_str=trim((string)post('reps_per_set'));
    $rpe = post('rpe')!=='' ? max(1,min(10,(int)post('rpe'))) : null;
    $obs = trim((string)post('observations')) ?: null;
    $dt = DateTime::createFromFormat('Y-m-d', $session_date, new DateTimeZone('Europe/Madrid'));
    $week_number=(int)$dt->format('W');

    $st=pdo()->prepare("
      UPDATE exercise_logs
      SET session_date=?, weight_kg=?, series=?, reps_per_set=?, rpe=?, observations=?, week_number=?
      WHERE id=? AND user_id=?
    ");
    $st->execute([$session_date,$weight,$series,$reps_str,$rpe,$obs,$week_number,$log_id,uid()]);

    // Volver a calcular objetivo con el historial actualizado
    // Necesitamos el nombre del ejercicio del log editado
    $sel=pdo()->prepare("SELECT exercise_name, day_id FROM exercise_logs WHERE id=? AND user_id=?");
    $sel->execute([$log_id, uid()]);
    $row=$sel->fetch();
    if($row){
      update_next_target_for_day_exercise((int)$row['day_id'], (string)$row['exercise_name']);
    }

    go(['view'=>'day','day_id'=>$day_id]);
  }
  if ($act==='delete_log'){
    $log_id=(int)post('log_id'); $redir_day=(int)post('day_id');
    // obtener info antes de borrar
    $sel=pdo()->prepare("SELECT exercise_name, day_id FROM exercise_logs WHERE id=? AND user_id=?");
    $sel->execute([$log_id, uid()]);
    $row=$sel->fetch();

    $st=pdo()->prepare("DELETE FROM exercise_logs WHERE id=? AND user_id=?");
    $st->execute([$log_id,uid()]);

    if($row){
      update_next_target_for_day_exercise((int)$row['day_id'], (string)$row['exercise_name']);
    }

    go(['view'=>'day','day_id'=>$redir_day]);
  }
}

// ====== LÓGICA SOBRECARGA (automática, basada en ciencia) ======
function get_last_logs_for_ex_in_day($day_id, $exercise_name, $limit=6){
  $st=pdo()->prepare("SELECT * FROM exercise_logs WHERE user_id=? AND day_id=? AND exercise_name=? ORDER BY session_date DESC, id DESC LIMIT ?");
  $st->bindValue(1, uid(), PDO::PARAM_INT);
  $st->bindValue(2, (int)$day_id, PDO::PARAM_INT);
  $st->bindValue(3, $exercise_name, PDO::PARAM_STR);
  $st->bindValue(4, (int)$limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll();
}

// Encuentra el registro en day_exercises
function get_day_exercise_row($day_id, $exercise_name){
  $st=pdo()->prepare("SELECT * FROM day_exercises WHERE day_id=? AND exercise_name=? ORDER BY id DESC LIMIT 1");
  $st->execute([$day_id, $exercise_name]);
  return $st->fetch();
}

/*
 Reglas (doble progresión + RPE + estancamiento + deload) con saltos de 2.5 kg:
 - Si completó el rango (min >= reps_min y max >= reps_max) y RPE <= 7 -> +2.5 kg, reps = reps_min, series = series base.
 - Si está dentro del rango pero no tocó el máximo y RPE <= 8 -> mantener peso, objetivo reps = reps_max.
 - Si reps bajan frente a la sesión anterior o RPE >= 9 -> deload (-2.5 kg) y reps = reps_min.
 - Si 3 sesiones sin mejora (mismo peso y sin subir promedio de reps) -> +1 serie (hasta 5).
 - Si no hay históricos -> objetivo inicial = series base, reps = reps_min, peso = (si hay last log global del ejercicio en cualquier día, úsalo; si no, null).
*/
// ====== LÓGICA SOBRECARGA REFINADA (científica y coherente) ======
function compute_next_target($day_id, $e_row){
  $exercise_name = $e_row['exercise_name'];
  $series_base = (int)$e_row['series'];
  $reps_min = (int)$e_row['reps_min'];
  $reps_max = (int)$e_row['reps_max'];
  $logs = get_last_logs_for_ex_in_day($day_id, $exercise_name, 6);

  $next_weight = null;
  $next_series = $series_base;
  $next_reps   = $reps_min;

  // Si no hay logs, busca el último global o establece peso base
  if (!$logs) {
    $st = pdo()->prepare("UPDATE day_exercises SET next_target_weight=?, next_target_reps=?, next_target_series=? WHERE id=?");
    $any = $st->fetch();
    $next_weight = $any ? max(2.5, round_to_2_5((float)$any['weight_kg'])) : 20; // peso inicial por defecto 20kg
    return [$next_weight, $reps_min, $series_base];
  }

  // Última sesión
  $curr = $logs[0];
  $curr_reps = parse_reps($curr['reps_per_set']);
  if (count($curr_reps) == 0) return [$curr['weight_kg'], $reps_min, $series_base];

  $curr_avg = array_sum($curr_reps)/count($curr_reps);
  $curr_min = min($curr_reps);
  $curr_max = max($curr_reps);
  $curr_rpe = $curr['rpe'] !== null ? (int)$curr['rpe'] : 7;
  $curr_w   = max(2.5, round_to_2_5((float)$curr['weight_kg']));
  $curr_s   = (int)$curr['series'];

  // Comparar con sesión anterior
  $prev = isset($logs[1]) ? $logs[1] : null;
  $reps_down = false;
  if ($prev) {
    $prev_reps = parse_reps($prev['reps_per_set']);
    if (count($prev_reps)) {
      $prev_avg = array_sum($prev_reps)/count($prev_reps);
      $reps_down = ($curr_avg < $prev_avg - 0.5);
    }
  }

  // Detectar mejor histórico para ver si hay estancamiento real
  $best = 0;
  foreach ($logs as $l) {
    $w = (float)$l['weight_kg'];
    $r = parse_reps($l['reps_per_set']);
    $avg = count($r) ? array_sum($r)/count($r) : 0;
    $perf = $w * $avg;
    if ($perf > $best) $best = $perf;
  }

  // Contar sesiones sin mejorar el mejor histórico
    $logs_rev = array_reverse($logs);
    $streak_no_prog = 0;
    foreach ($logs_rev as $l) {
    $w = (float)$l['weight_kg'];
    $r = parse_reps($l['reps_per_set']);
    $avg = count($r) ? array_sum($r)/count($r) : 0;
    $perf = $w * $avg;
    if ($perf < $best * 0.995) $streak_no_prog++;
    else break;
    }


  // === Reglas refinadas ===
  if ($curr_min >= $reps_min && $curr_max >= $reps_max && $curr_rpe <= 7) {
    // Rendimiento óptimo: subir peso
    $next_weight = inc_2_5($curr_w);
    $next_reps   = $reps_min;
  } elseif ($curr_min >= $reps_min && $curr_max < $reps_max && $curr_rpe <= 8) {
    // Aumentar reps dentro del rango
    $next_weight = $curr_w;
    $next_reps   = min($curr_max + 1, $reps_max);
  } elseif ($reps_down || $curr_rpe >= 9) {
    // Deload por fatiga
    $next_weight = dec_2_5($curr_w);
    $next_reps   = $reps_min;
  } else {
    // Mantener
    $next_weight = $curr_w;
    $next_reps   = ($curr_max >= $reps_max) ? $reps_min : $reps_max;
  }

  // Aumentar volumen si lleva 3+ sesiones sin progreso y no viene de deload
  if ($streak_no_prog >= 3 && !$reps_down && $curr_rpe <= 8) {
    $next_series = min(5, $curr_s + 1);
  }

  return [max(2.5, $next_weight), $next_reps, $next_series];
}


// Guarda el nuevo objetivo en day_exercises
function update_next_target_for_day_exercise($day_id, $exercise_name){
  $e = get_day_exercise_row($day_id, $exercise_name);
  if(!$e) return;
  list($w,$r,$s) = compute_next_target($day_id, $e);
  $st=pdo()->prepare("
    UPDATE day_exercises SET next_target_weight=?, next_target_reps=?, next_target_series=?
    WHERE id=? LIMIT 1
  ");
  $st->execute([$w, $r, $s, $e['id']]);
}

// Cargar objetivos actuales (si están vacíos, calcula una vez sin guardarlos)
function read_target_display($w, $r, $s){
  if ($w === null) return "🎯 Próximo: —";
  $w_disp = rtrim(rtrim(number_format($w,2,'.',''), '0'), '.');
  return "🎯 Próximo: ".(int)$s."×".(int)$r." @ ".$w_disp." kg";
}


// ====== VISTA (UI MINIMAL) ======
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<title>Gym Coach</title>
<style>
  :root{ --bg:#0f1114; --card:#15181d; --text:#e6e8eb; --muted:#9aa0a6; --line:#242a33; }
  *{ box-sizing:border-box; }
    body{ 
    margin:0; 
    font-family: ui-rounded, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; 
    background:var(--bg); 
    color:var(--text); 
    font-size:16px; /* 🔥 antes era ~14px */
    }
  a{ color:inherit; text-decoration:none; }
  .wrap{ max-width:420px; margin:0 auto; padding:12px 10px 90px; }
  .bar{ position:sticky; top:0; z-index:10; background:rgba(15,17,20,.8); border-bottom:1px solid var(--line); backdrop-filter: blur(6px); }
  .bar-inner{ display:flex; align-items:center; gap:8px; padding:8px 10px; }
  .brand{ font-weight:800; letter-spacing:.2px; font-size:14px; }
  .btn{ appearance:none; border:1px solid var(--line); border-radius:10px; padding:8px 10px; background:#14171b; color:var(--text); font-weight:700; font-size:13px; }
  .btn.min{ padding:6px 8px; font-weight:700; font-size:12px; }
  .grid{ display:grid; gap:8px; }
  .card{ background:var(--card); border:1px solid var(--line); border-radius:14px; padding:10px; }
  input, select, textarea{ width:100%; padding:8px 10px; border-radius:10px; border:1px solid var(--line); background:#0f1216; color:var(--text); font-size:14px; }
  label{ font-size:11px; color:var(--muted); margin-bottom:4px; display:block; }
  .row{ display:flex; gap:6px; }
  .row > *{ flex:1; }
  .title{ font-weight:800; font-size:16px; margin:4px 0 8px; }
  .hint{ font-size:11px; color:var(--muted); }
  .list{ display:flex; flex-direction:column; gap:8px; }
  .footer{ position:fixed; left:0; right:0; bottom:0; background:rgba(15,17,20,.9); border-top:1px solid var(--line); padding:8px 10px; display:flex; gap:8px; }
  .navbtn{ flex:1; text-align:center; padding:10px; border-radius:10px; border:1px solid var(--line); background:#12151a; color:var(--text); font-weight:800; font-size:13px; }
  .tag{ display:inline-block; font-size:12px; color:var(--muted); border:1px dashed var(--line); padding:4px 6px; border-radius:8px; }
  summary{ cursor:pointer; font-size:12px; }
  .rowcenter{ display:flex; align-items:center; gap:6px; }
/* === CHECK VISUAL (versión más equilibrada) === */
.btn.check {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  font-weight: 800;
  font-size: 16px; /* más pequeño */
  text-align: center;
  line-height: 1;
  transition: all 0.2s ease;
  background: #14171b;
  color: var(--muted);
}
.btn.check.checked {
  background: #16a34a; /* verde más suave */
  border-color: #16a34a;
  color: #fff;
  transform: scale(1.03);
  box-shadow: 0 0 6px rgba(22,163,74,0.6);
}


</style>
</head>
<body>
  <div class="bar">
    <div class="bar-inner">
      <div class="brand">🏋️‍♂️ Gym Coach</div>
      <div style="margin-left:auto">
        <?php if(is_logged()): ?>
          <span class="tag"><?=esc($_SESSION['uname'])?></span>
          <a class="btn min" href="?view=home">Inicio</a>
          <a class="btn min" href="?logout=1">Salir</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="wrap">
<?php if(!is_logged()): ?>
  <!-- LOGIN / REGISTRO -->
  <div class="card">
    <div class="title">Entrar</div>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="login">
      <div>
        <label>Usuario</label>
        <input name="username" placeholder="alvaro" required>
      </div>
      <div>
        <label>PIN</label>
        <input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" placeholder="••••" required>
      </div>
      <button class="btn" type="submit">Entrar</button>
    </form>
  </div>

  <div class="card">
    <div class="title">Crear cuenta</div>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="register">
      <div class="row">
        <div>
          <label>Usuario</label>
          <input name="username" required>
        </div>
        <div>
          <label>PIN</label>
          <input name="pin" type="password" inputmode="numeric" pattern="[0-9]*" placeholder="4-8 dígitos" required>
        </div>
        <div>
          <label>Confirmar PIN</label>
          <input name="pin2" type="password" inputmode="numeric" pattern="[0-9]*" required>
        </div>
      </div>
      <button class="btn" type="submit">Registrarme</button>
    </form>
  </div>

  <?php if(isset($error)): ?><p class="hint" style="color:#f66"><?=esc($error)?></p><?php endif; ?>

<?php else:
  $view = getv('view','home');
  $edit = getv('edit') ? true : false;

  if ($view==='home'):
    $routines = user_routines(); ?>
    <div class="card">
      <div class="title">Tus rutinas</div>
      <?php if(!$routines): ?><p class="hint">Crea tu primera rutina</p><?php endif; ?>
      <div class="list">
        <?php foreach($routines as $r): ?>
          <div class="card" style="padding:8px">
            <div class="rowcenter">
              <div style="flex:1">
                <div style="font-weight:800; font-size:14px"><?=esc($r['name'])?></div>
                <div class="hint"><?=$r['days']?> días</div>
              </div>
              <a class="btn min" href="?view=routine&rid=<?=$r['id']?>">Abrir</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="title">Crear rutina</div>
      <form method="post" class="grid">
        <input type="hidden" name="action" value="create_routine">
        <label>Nombre</label>
        <input name="routine_name" placeholder="Full Body / PPL / etc.">
        <label>Días</label>
        <input name="routine_days" type="number" min="1" max="7" value="3" required>
        <button class="btn" type="submit">Crear</button>
      </form>
    </div>

  <?php elseif ($view==='routine'):
      $rid=(int)getv('rid'); $routine=routine_with_days($rid);
      if(!$routine){ echo "<p class='hint' style='color:#f66'>Rutina no encontrada.</p>"; }
      else { ?>
      <div class="card">
        <div class="title">Rutina: <?=esc($routine['name'])?></div>
        <div class="row" style="margin-bottom:8px">
          <a class="btn min" href="?view=routine&rid=<?=$routine['id']?>">Vista</a>
          <a class="btn min" href="?view=routine&rid=<?=$routine['id']?>&edit=1">Editar</a>
        </div>
        <?php if($edit): ?>
          <form method="post" class="row" style="margin-bottom:8px">
            <input type="hidden" name="action" value="rename_routine">
            <input type="hidden" name="rid" value="<?=$routine['id']?>">
            <input name="routine_name" value="<?=esc($routine['name'])?>">
            <button class="btn min" type="submit">Renombrar</button>
          </form>
        <?php endif; ?>
        <div class="list">
          <?php foreach($routine['days_rows'] as $d): ?>
            <a class="btn" href="?view=day&day_id=<?=$d['id']?><?= $edit ? '&edit=1':'' ?>">➡️ <?=esc($d['day_name'])?></a>
          <?php endforeach; ?>
        </div>
        <?php if($edit): ?>
          <form method="post" onsubmit="return confirm('¿Eliminar rutina completa?');" style="margin-top:8px">
            <input type="hidden" name="action" value="delete_routine">
            <input type="hidden" name="rid" value="<?=$routine['id']?>">
            <button class="btn" type="submit">Eliminar rutina</button>
          </form>
        <?php endif; ?>
      </div>
    <?php } ?>

  <?php elseif ($view==='day'):
      $day_id=(int)getv('day_id'); $d=day_detail($day_id);
      if(!$d){ echo "<p class='hint' style='color:#f66'>Día no encontrado.</p>"; }
      else { ?>
      <div class="card">
        <div class="title"><?=esc($d['routine_name'])?> · <?=esc($d['day_name'])?></div>
        <div class="row" style="margin-bottom:8px">
          <a class="btn min" href="?view=day&day_id=<?=$d['id']?>">Vista</a>
          <a class="btn min" href="?view=day&day_id=<?=$d['id']?>&edit=1">Editar</a>
        </div>
        <?php if($edit): ?>
          <form method="post" class="row">
            <input type="hidden" name="action" value="rename_day">
            <input type="hidden" name="day_id" value="<?=$d['id']?>">
            <input name="day_name" value="<?=esc($d['day_name'])?>">
            <button class="btn min" type="submit">Renombrar día</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="title">Ejercicios</div>
        <div class="list">
          <?php foreach($d['exercises'] as $e):
            $checked = isset($d['checks_today'][$e['id']]) ? $d['checks_today'][$e['id']] : 0;
            // Historial para este ejercicio (en este día)
            $st=pdo()->prepare("SELECT * FROM exercise_logs WHERE user_id=? AND day_id=? AND exercise_name=? ORDER BY session_date DESC, id DESC LIMIT 6");
            $st->execute([uid(), $d['id'], $e['exercise_name']]);
            $logs = $st->fetchAll();
          ?>
            <div class="card" style="padding:8px">
              <div class="rowcenter">
                <form method="post" style="margin:0">
                  <input type="hidden" name="action" value="toggle_check">
                  <input type="hidden" name="day_id" value="<?=$d['id']?>">
                  <input type="hidden" name="ex_id" value="<?=$e['id']?>">
                  <button class="btn check <?= $checked ? 'checked' : '' ?>" type="submit">
                    <?= $checked ? '✔' : '□' ?>
                  </button>

                </form>
                <div style="flex:1; font-size:14px; font-weight:800"><?=esc($e['exercise_name'])?></div>
              </div>
              <div class="hint" style="margin-top:4px">
                <?= (int)$e['series'] ?>× · objetivo <?= (int)$e['reps_min'] ?>-<?= (int)$e['reps_max'] ?> reps<?= $e['notes'] ? ' · '.esc($e['notes']) : '' ?>
              </div>

              <!-- Objetivo (solo texto compacto) -->
              <div style="margin-top:6px; font-size:13px; font-weight:800;">
                <?=esc(read_target_display($e, $d['id']))?>
              </div>

              <!-- Anotar sesión (plegable, minimal) -->
              <details style="margin-top:6px">
                <summary>📝 Anotar sesión</summary>
                <form method="post" class="grid" style="margin-top:6px">
                  <input type="hidden" name="action" value="save_log">
                  <input type="hidden" name="rid" value="<?=$d['routine_id']?>">
                  <input type="hidden" name="day_id" value="<?=$d['id']?>">
                  <input type="hidden" name="exercise_name" value="<?=esc($e['exercise_name'])?>">
                  <label>Fecha</label>
                  <input name="session_date" type="date" value="<?=today()?>" required>
                  <div class="row">
                    <div>
                      <label>Peso (kg)</label>
                      <input name="weight_kg" type="number" step="2.5" min="0" value="<?= $e['next_target_weight']!==null? esc($e['next_target_weight']) : '0' ?>" required>
                    </div>
                    <div>
                      <label>Series</label>
                      <input name="series" type="number" min="1" value="<?= $e['next_target_series']!==null? (int)$e['next_target_series'] : (int)$e['series'] ?>" required>
                    </div>
                  </div>
                  <label>Reps por serie</label>
                  <input name="reps_per_set" placeholder="10, 9, 8" value="<?= $e['next_target_reps']!==null? (int)$e['next_target_reps'] : (int)$e['reps_min'] ?>" required>
                  <div class="row">
                    <div>
                      <label>RPE (1-10, opcional)</label>
                      <input name="rpe" type="number" min="1" max="10" placeholder="7">
                    </div>
                    <div>
                      <label>Observaciones (opcional)</label>
                      <input name="observations" placeholder="Control, técnica, etc.">
                    </div>
                  </div>
                  <button class="btn" type="submit">Guardar</button>
                </form>
              </details>

              <!-- Historial (editar/borrar) -->
              <?php if($logs): ?>
              <details style="margin-top:6px">
                <summary>🗂 Historial (editar/borrar)</summary>
                <div class="list" style="margin-top:6px">
                  <?php foreach($logs as $log): ?>
                  <div class="card" style="padding:8px">
                    <div style="font-weight:700; font-size:13px">
                      <?=esc($log['session_date'])?> — <?=esc(rtrim(rtrim(number_format((float)$log['weight_kg'],2,'.',''), '0'), '.'))?> kg · reps: <?=esc($log['reps_per_set'])?><?= $log['rpe']!==null ? ' · RPE '.esc($log['rpe']) : '' ?>
                    </div>
                    <details style="margin-top:6px">
                      <summary>✏️ Editar</summary>
                      <form method="post" class="grid" style="margin-top:6px">
                        <input type="hidden" name="action" value="update_log">
                        <input type="hidden" name="log_id" value="<?=$log['id']?>">
                        <input type="hidden" name="day_id" value="<?=$d['id']?>">
                        <label>Fecha</label>
                        <input name="session_date" type="date" value="<?=esc($log['session_date'])?>" required>
                        <div class="row">
                          <div>
                            <label>Peso (kg)</label>
                            <input name="weight_kg" type="number" step="2.5" min="0" value="<?=esc($log['weight_kg'])?>" required>
                          </div>
                          <div>
                            <label>Series</label>
                            <input name="series" type="number" min="1" value="<?=esc($log['series'])?>" required>
                          </div>
                        </div>
                        <label>Reps por serie</label>
                        <input name="reps_per_set" value="<?=esc($log['reps_per_set'])?>" required>
                        <div class="row">
                          <div>
                            <label>RPE (1-10, opcional)</label>
                            <input name="rpe" type="number" min="1" max="10" value="<?=esc($log['rpe'])?>">
                          </div>
                          <div>
                            <label>Observaciones (opcional)</label>
                            <input name="observations" value="<?=esc($log['observations'])?>">
                          </div>
                        </div>
                        <button class="btn" type="submit">Guardar cambios</button>
                      </form>
                    </details>
                    <form method="post" onsubmit="return confirm('¿Eliminar registro?');" style="margin-top:6px">
                      <input type="hidden" name="action" value="delete_log">
                      <input type="hidden" name="log_id" value="<?=$log['id']?>">
                      <input type="hidden" name="day_id" value="<?=$d['id']?>">
                      <button class="btn" type="submit">Eliminar</button>
                    </form>
                  </div>
                  <?php endforeach; ?>
                </div>
              </details>
              <?php endif; ?>

              <?php if($edit): ?>
              <details style="margin-top:6px">
                <summary>⚙️ Editar ejercicio</summary>
                <form method="post" class="grid" style="margin-top:6px">
                  <input type="hidden" name="action" value="edit_ex">
                  <input type="hidden" name="day_id" value="<?=$d['id']?>">
                  <input type="hidden" name="ex_id" value="<?=$e['id']?>">
                  <label>Nombre</label>
                  <input name="exercise_name" value="<?=esc($e['exercise_name'])?>">
                  <div class="row">
                    <div>
                      <label>Series</label>
                      <input name="series" type="number" min="1" value="<?=$e['series']?>">
                    </div>
                    <div>
                      <label>Reps min</label>
                      <input name="reps_min" type="number" min="1" value="<?=$e['reps_min']?>">
                    </div>
                    <div>
                      <label>Reps max</label>
                      <input name="reps_max" type="number" min="1" value="<?=$e['reps_max']?>">
                    </div>
                  </div>
                  <label>Notas</label>
                  <input name="notes" value="<?=esc($e['notes'])?>">
                  <button class="btn" type="submit">Guardar</button>
                </form>
                <form method="post" onsubmit="return confirm('¿Eliminar ejercicio?');" style="margin-top:6px">
                  <input type="hidden" name="action" value="del_ex">
                  <input type="hidden" name="day_id" value="<?=$d['id']?>">
                  <input type="hidden" name="ex_id" value="<?=$e['id']?>">
                  <button class="btn" type="submit">Eliminar</button>
                </form>
              </details>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if($edit): ?>
        <div class="card" style="margin-top:8px">
          <div class="title">Añadir ejercicio</div>
          <form method="post" class="grid">
            <input type="hidden" name="action" value="add_ex">
            <input type="hidden" name="day_id" value="<?=$d['id']?>">
            <label>Nombre</label>
            <input name="exercise_name" placeholder="Sentadilla / Press banca..." required>
            <div class="row">
              <div>
                <label>Series</label>
                <input name="series" type="number" min="1" value="3" required>
              </div>
              <div>
                <label>Reps min</label>
                <input name="reps_min" type="number" min="1" value="8" required>
              </div>
              <div>
                <label>Reps max</label>
                <input name="reps_max" type="number" min="1" value="12" required>
              </div>
            </div>
            <label>Notas (opcional)</label>
            <input name="notes" placeholder="Tempo 3-1-1, rango completo...">
            <button class="btn" type="submit">Añadir</button>
          </form>
        </div>
      <?php endif; ?>

      <form method="post" style="margin-top:8px">
        <input type="hidden" name="action" value="clear_checks">
        <input type="hidden" name="day_id" value="<?=$d['id']?>">
        <button class="btn" type="submit">Limpiar checks de hoy</button>
      </form>
    <?php } endif; endif; ?>

  </div>

  <?php if(is_logged()): ?>
  <div class="footer">
    <a class="navbtn" href="?view=home">🏠 Inicio</a>
    <a class="navbtn" href="?logout=1">🚪 Salir</a>
  </div>
  <?php endif; ?>
</body>
</html>
