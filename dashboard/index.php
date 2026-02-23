<?php
require_once __DIR__ . '/config.php';

// --- Twilio API helper ---
function twilio_api($endpoint, $method = 'GET', $data = null) {
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true)];
}

// --- Hotel context ---
$hotels   = db()->query('SELECT * FROM hotels ORDER BY name')->fetchAll();
$hotel_id = (int)($_GET['hotel'] ?? ($_POST['hotel'] ?? 0));
if (!$hotel_id && !empty($hotels)) {
    $hotel_id = $hotels[0]['id'];
}

$hotel          = null;
$hotel_numbers  = [];
$agent_settings = null;
if ($hotel_id) {
    $stmt = db()->prepare('SELECT * FROM hotels WHERE id = ?');
    $stmt->execute([$hotel_id]);
    $hotel = $stmt->fetch();

    $stmt = db()->prepare('SELECT * FROM phone_numbers WHERE hotel_id = ?');
    $stmt->execute([$hotel_id]);
    $hotel_numbers = $stmt->fetchAll();

    $stmt = db()->prepare('SELECT * FROM agent_settings WHERE hotel_id = ?');
    $stmt->execute([$hotel_id]);
    $agent_settings = $stmt->fetch() ?: null;
}

$page = $_GET['page'] ?? 'dashboard';

// --- AI toggle ---
$toast      = '';
$toast_type = '';
if (isset($_POST['toggle_ai']) && $hotel_id && $hotel_numbers) {
    $enable = $_POST['toggle_ai'] === 'on';
    foreach ($hotel_numbers as $num) {
        if ($enable) {
            $current      = twilio_api('/IncomingPhoneNumbers/' . $num['twilio_number_sid'] . '.json');
            $original_url = $current['body']['voice_url'] ?? '';
            db()->prepare('UPDATE phone_numbers SET original_voice_url = ?, ai_enabled = 1 WHERE id = ?')
               ->execute([$original_url, $num['id']]);
            twilio_api('/IncomingPhoneNumbers/' . $num['twilio_number_sid'] . '.json', 'POST', [
                'VoiceUrl' => BOT_WEBHOOK_URL,
            ]);
        } else {
            $row_stmt = db()->prepare('SELECT original_voice_url FROM phone_numbers WHERE id = ?');
            $row_stmt->execute([$num['id']]);
            $row         = $row_stmt->fetch();
            $restore_url = $row['original_voice_url'] ?? '';
            twilio_api('/IncomingPhoneNumbers/' . $num['twilio_number_sid'] . '.json', 'POST', [
                'VoiceUrl' => $restore_url,
            ]);
            db()->prepare('UPDATE phone_numbers SET ai_enabled = 0 WHERE id = ?')
               ->execute([$num['id']]);
        }
    }
    // Reload hotel_numbers after update
    $stmt          = db()->prepare('SELECT * FROM phone_numbers WHERE hotel_id = ?');
    $stmt->execute([$hotel_id]);
    $hotel_numbers = $stmt->fetchAll();
    $toast         = $enable ? 'AI Concierge activated successfully' : 'AI Concierge deactivated';
    $toast_type    = $enable ? 'success' : 'warning';
}

// --- AI Agent settings save ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'ai-agent' && $hotel_id && !isset($_POST['toggle_ai'])) {
    db()->prepare("
        INSERT INTO agent_settings (hotel_id, greeting_message, language, elevenlabs_voice_id, system_prompt, updated_at)
        VALUES (?, ?, ?, ?, ?, datetime('now'))
        ON CONFLICT(hotel_id) DO UPDATE SET
            greeting_message    = excluded.greeting_message,
            language            = excluded.language,
            elevenlabs_voice_id = excluded.elevenlabs_voice_id,
            system_prompt       = excluded.system_prompt,
            updated_at          = excluded.updated_at
    ")->execute([
        $hotel_id,
        trim($_POST['greeting']      ?? ''),
        trim($_POST['language']      ?? 'en'),
        trim($_POST['voice_id']      ?? ''),
        trim($_POST['system_prompt'] ?? ''),
    ]);
    $stmt2 = db()->prepare('SELECT * FROM agent_settings WHERE hotel_id = ?');
    $stmt2->execute([$hotel_id]);
    $agent_settings = $stmt2->fetch();
    $toast      = 'Agent settings saved';
    $toast_type = 'success';
}

// --- Knowledge base upload / paste / delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'knowledge' && $hotel_id && !isset($_POST['toggle_ai'])) {
    if (!empty($_POST['delete_entry'])) {
        db()->prepare('DELETE FROM knowledge_entries WHERE id = ? AND hotel_id = ?')
           ->execute([(int)$_POST['delete_entry'], $hotel_id]);
        $toast      = 'Entry deleted';
        $toast_type = 'warning';
    } elseif (!empty($_POST['paste_name']) && !empty($_POST['paste_content'])) {
        db()->prepare('INSERT INTO knowledge_entries (hotel_id, source_name, content) VALUES (?, ?, ?)')
           ->execute([$hotel_id, trim($_POST['paste_name']), trim($_POST['paste_content'])]);
        $toast      = 'Knowledge entry added';
        $toast_type = 'success';
    } elseif (isset($_FILES['kb_file']) && $_FILES['kb_file']['error'] === UPLOAD_ERR_OK) {
        $fname = basename($_FILES['kb_file']['name']);
        $ext   = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
        if (in_array($ext, ['txt', 'csv', 'md'])) {
            $content = file_get_contents($_FILES['kb_file']['tmp_name']);
            if ($content !== false && strlen(trim($content)) > 0) {
                db()->prepare('INSERT INTO knowledge_entries (hotel_id, source_name, content) VALUES (?, ?, ?)')
                   ->execute([$hotel_id, $fname, $content]);
                $toast      = htmlspecialchars($fname) . ' uploaded successfully';
                $toast_type = 'success';
            } else {
                $toast      = 'File appears to be empty';
                $toast_type = 'warning';
            }
        } else {
            $toast      = 'Only TXT, CSV, and MD files are supported. Paste PDF content using the text area below.';
            $toast_type = 'warning';
        }
    }
}

// --- Properties: add hotel ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'properties' && !isset($_POST['toggle_ai'])) {
    $new_name = trim($_POST['hotel_name'] ?? '');
    $new_slug = trim(preg_replace('/[^a-z0-9-]/', '-', strtolower($_POST['hotel_slug'] ?? '')), '-');
    if ($new_name && $new_slug) {
        try {
            $ins = db()->prepare('INSERT INTO hotels (name, slug) VALUES (?, ?)');
            $ins->execute([$new_name, $new_slug]);
            $new_id   = (int)db()->lastInsertId();
            db()->prepare('INSERT OR IGNORE INTO agent_settings (hotel_id) VALUES (?)')->execute([$new_id]);
            $hotels   = db()->query('SELECT * FROM hotels ORDER BY name')->fetchAll();
            $hotel_id = $new_id;
            $hotel    = ['id' => $hotel_id, 'name' => $new_name, 'slug' => $new_slug];
            $toast      = htmlspecialchars($new_name) . ' added';
            $toast_type = 'success';
        } catch (Exception $e) {
            $toast      = 'Slug already in use — choose a unique identifier';
            $toast_type = 'warning';
        }
    }
}

// --- AI active state from DB ---
$ai_active = false;
foreach ($hotel_numbers as $num) {
    if ($num['ai_enabled']) { $ai_active = true; break; }
}

// --- Fetch Twilio call data ---
$all_calls   = [];
$recordings  = [];
$today_count = 0;
if (in_array($page, ['dashboard', 'calls']) && $hotel_numbers) {
    $today = date('Y-m-d');
    foreach ($hotel_numbers as $num) {
        $calls_data = twilio_api('/Calls.json?To=' . urlencode($num['twilio_number']) . '&PageSize=50');
        if ($calls_data['code'] === 200) {
            $all_calls = array_merge($all_calls, $calls_data['body']['calls'] ?? []);
        }
        // Separate call for accurate today count
        $today_data = twilio_api(
            '/Calls.json?To=' . urlencode($num['twilio_number']) .
            '&StartTime%3E=' . urlencode($today) . '&PageSize=1'
        );
        if ($today_data['code'] === 200) {
            $today_count += (int)($today_data['body']['total'] ?? 0);
        }
    }
    usort($all_calls, fn($a, $b) => strtotime($b['date_created']) - strtotime($a['date_created']));

    $rec_data = twilio_api('/Recordings.json?PageSize=50');
    $raw_recs = $rec_data['code'] === 200 ? ($rec_data['body']['recordings'] ?? []) : [];
    foreach ($raw_recs as $r) {
        $recordings[$r['call_sid']][] = $r;
    }
}

// Stats
$total_calls    = count($all_calls);
$total_duration = 0;
$answered       = 0;
foreach ($all_calls as $c) {
    if ((int)$c['duration'] > 0) { $total_duration += (int)$c['duration']; $answered++; }
}
$avg_duration = $answered > 0 ? round($total_duration / $answered) : 0;

// Knowledge entries
$kb_entries = [];
if ($page === 'knowledge' && $hotel_id) {
    $stmt = db()->prepare('SELECT * FROM knowledge_entries WHERE hotel_id = ? ORDER BY created_at DESC');
    $stmt->execute([$hotel_id]);
    $kb_entries = $stmt->fetchAll();
}

// Properties list
$all_hotels_for_props = [];
if ($page === 'properties') {
    $all_hotels_for_props = db()->query(
        'SELECT h.*, COUNT(p.id) AS number_count FROM hotels h
         LEFT JOIN phone_numbers p ON p.hotel_id = h.id
         GROUP BY h.id ORDER BY h.name'
    )->fetchAll();
}

function nav_link(string $pg, int $hotel_id): string {
    return '?page=' . $pg . ($hotel_id ? '&hotel=' . $hotel_id : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PBX AI<?= $hotel ? ' — ' . htmlspecialchars($hotel['name']) : '' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sidebar: { DEFAULT:'#0f172a', light:'#1e293b' },
                        accent:  { DEFAULT:'#6366f1', light:'#818cf8', dark:'#4f46e5' },
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        * { font-family: 'Inter', sans-serif; }
        .scrollbar-thin::-webkit-scrollbar { width: 4px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        audio { height: 32px; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex">

<!-- Sidebar -->
<aside class="fixed inset-y-0 left-0 w-[260px] bg-sidebar flex flex-col z-50">
    <!-- Logo -->
    <div class="px-5 py-5 border-b border-white/5">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-accent rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
            </div>
            <div>
                <h1 class="text-white font-bold text-[15px]">PBX AI</h1>
                <p class="text-slate-500 text-[11px]">Voice Intelligence Platform</p>
            </div>
        </div>
    </div>

    <!-- Property selector -->
    <div class="px-4 py-4 border-b border-white/5">
        <label class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2 block">Property</label>
        <?php if (count($hotels) > 1): ?>
        <form method="GET">
            <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
            <select name="hotel" onchange="this.form.submit()"
                class="w-full bg-sidebar-light text-white text-[13px] rounded-lg px-3 py-2.5 border border-white/10 cursor-pointer focus:outline-none">
                <?php foreach ($hotels as $h): ?>
                <option value="<?= $h['id'] ?>" <?= $h['id'] == $hotel_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($h['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php else: ?>
        <div class="bg-sidebar-light rounded-lg px-3 py-2.5 flex items-center gap-2">
            <div class="w-7 h-7 bg-accent/20 rounded-md flex items-center justify-center text-[11px] font-bold text-accent-light">
                <?= $hotel ? strtoupper(substr($hotel['name'], 0, 2)) : '—' ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-white text-[13px] font-medium truncate"><?= $hotel ? htmlspecialchars($hotel['name']) : 'No property' ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto scrollbar-thin">
        <p class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold px-3 mb-2">Main</p>
        <a href="<?= nav_link('dashboard', $hotel_id) ?>" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'dashboard' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            Dashboard
        </a>
        <a href="<?= nav_link('calls', $hotel_id) ?>" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'calls' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Call History
        </a>

        <p class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold px-3 mb-2 mt-6">Configure</p>
        <a href="<?= nav_link('ai-agent', $hotel_id) ?>" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'ai-agent' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            AI Agent
        </a>
        <a href="<?= nav_link('knowledge', $hotel_id) ?>" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'knowledge' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Knowledge Base
        </a>
        <a href="<?= nav_link('phone', $hotel_id) ?>" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'phone' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            Phone Numbers
        </a>

        <p class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold px-3 mb-2 mt-6">Admin</p>
        <a href="?page=properties" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'properties' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            Properties
        </a>
    </nav>

    <!-- AI status + toggle -->
    <div class="px-4 py-4 border-t border-white/5">
        <?php if ($hotel_id): ?>
        <form method="POST">
            <input type="hidden" name="hotel" value="<?= $hotel_id ?>">
            <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
            <button type="submit" name="toggle_ai" value="<?= $ai_active ? 'off' : 'on' ?>"
                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg <?= $ai_active ? 'bg-emerald-500/10 hover:bg-emerald-500/15' : 'bg-red-500/10 hover:bg-red-500/15' ?> transition group">
                <span class="w-2 h-2 rounded-full <?= $ai_active ? 'bg-emerald-400 animate-pulse' : 'bg-red-400' ?>"></span>
                <span class="text-[13px] <?= $ai_active ? 'text-emerald-400' : 'text-red-400' ?> font-medium flex-1 text-left">
                    AI <?= $ai_active ? 'Active' : 'Offline' ?>
                </span>
                <span class="text-[11px] <?= $ai_active ? 'text-emerald-500/60' : 'text-red-500/60' ?> group-hover:text-white/60">
                    <?= $ai_active ? 'Turn off' : 'Turn on' ?>
                </span>
            </button>
        </form>
        <?php else: ?>
        <div class="px-3 py-2.5 text-[13px] text-slate-500">No property selected</div>
        <?php endif; ?>
    </div>
</aside>

<!-- Main Content -->
<main class="ml-[260px] flex-1 min-h-screen">

    <!-- Top bar -->
    <header class="h-14 border-b border-gray-200 bg-white flex items-center px-6 sticky top-0 z-40">
        <?php if ($toast): ?>
        <div class="flex items-center gap-2 text-sm <?= $toast_type === 'success' ? 'text-emerald-600' : 'text-amber-600' ?>">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <?= htmlspecialchars($toast) ?>
        </div>
        <?php else: ?>
        <p class="text-sm text-gray-500"><?= date('l, F j, Y') ?></p>
        <?php endif; ?>
    </header>

    <div class="p-6">

    <?php if ($page === 'dashboard'): ?>
    <!-- ==================== DASHBOARD ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Dashboard</h2>
        <p class="text-sm text-gray-500 mt-0.5">Overview of <?= $hotel ? htmlspecialchars($hotel['name']) : 'your property' ?></p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Today's Calls</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?= $today_count ?></p>
                </div>
                <div class="w-11 h-11 bg-accent/10 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Total Calls (Last 50)</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?= $total_calls ?></p>
                </div>
                <div class="w-11 h-11 bg-purple-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl p-5 border border-gray-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Avg Duration</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1"><?= gmdate($avg_duration >= 3600 ? 'H:i:s' : 'i:s', $avg_duration) ?></p>
                </div>
                <div class="w-11 h-11 bg-emerald-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-[15px]">Recent Calls</h3>
            <a href="<?= nav_link('calls', $hotel_id) ?>" class="text-xs text-accent hover:text-accent-dark font-medium">View all &rarr;</a>
        </div>
        <table class="w-full">
            <thead>
                <tr class="text-left text-[11px] font-semibold text-gray-400 uppercase tracking-wider border-b border-gray-50">
                    <th class="px-5 py-3">Caller</th>
                    <th class="px-5 py-3">Date</th>
                    <th class="px-5 py-3">Duration</th>
                    <th class="px-5 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach (array_slice($all_calls, 0, 8) as $call):
                    $from    = $call['from_formatted'] ?? $call['from'] ?? 'Unknown';
                    $date    = date('M j, g:i A', strtotime($call['date_created']));
                    $dur     = (int)($call['duration'] ?? 0);
                    $dur_fmt = $dur > 0 ? gmdate($dur >= 3600 ? 'H:i:s' : 'i:s', $dur) : '--:--';
                    $status  = $call['status'] ?? 'unknown';
                    $badge   = [
                        'completed'   => 'bg-emerald-50 text-emerald-600',
                        'in-progress' => 'bg-blue-50 text-blue-600',
                        'ringing'     => 'bg-amber-50 text-amber-600',
                        'busy'        => 'bg-orange-50 text-orange-600',
                        'no-answer'   => 'bg-red-50 text-red-600',
                        'failed'      => 'bg-red-50 text-red-600',
                    ][$status] ?? 'bg-gray-50 text-gray-500';
                ?>
                <tr class="hover:bg-gray-50/60 transition">
                    <td class="px-5 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($from) ?></td>
                    <td class="px-5 py-3 text-sm text-gray-500"><?= $date ?></td>
                    <td class="px-5 py-3 text-sm text-gray-500 font-mono text-[13px]"><?= $dur_fmt ?></td>
                    <td class="px-5 py-3"><span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-medium <?= $badge ?>"><?= $status ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($all_calls)): ?>
                <tr><td colspan="4" class="px-5 py-10 text-center text-gray-400 text-sm">No calls yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php elseif ($page === 'calls'): ?>
    <!-- ==================== CALL HISTORY ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Call History</h2>
        <p class="text-sm text-gray-500 mt-0.5">Recordings of all AI-handled calls</p>
    </div>

    <div class="space-y-3">
        <?php if (empty($all_calls)): ?>
        <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
            <p class="text-gray-400 text-sm">No calls recorded yet. Recordings will appear here once the AI handles calls.</p>
        </div>
        <?php else: ?>
        <?php foreach ($all_calls as $call):
            $call_sid      = $call['sid'];
            $from          = $call['from_formatted'] ?? $call['from'] ?? 'Unknown';
            $date          = date('M j, Y \a\t g:i A', strtotime($call['date_created']));
            $dur           = (int)($call['duration'] ?? 0);
            $dur_fmt       = $dur > 0 ? gmdate($dur >= 3600 ? 'H:i:s' : 'i:s', $dur) : '--:--';
            $status        = $call['status'] ?? 'unknown';
            $has_recording = isset($recordings[$call_sid]);
            $rec           = $has_recording ? $recordings[$call_sid][0] : null;
            $rec_url       = $rec ? 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Recordings/' . $rec['sid'] . '.mp3' : '';
        ?>
        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 flex items-center gap-4">
                <div class="w-10 h-10 rounded-full <?= $status === 'completed' ? 'bg-emerald-50' : 'bg-gray-50' ?> flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 <?= $status === 'completed' ? 'text-emerald-500' : 'text-gray-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($from) ?></span>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium <?= $status === 'completed' ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' ?>"><?= $status ?></span>
                    </div>
                    <p class="text-xs text-gray-400 mt-0.5"><?= $date ?> &middot; <?= $dur_fmt ?></p>
                </div>
                <?php if ($has_recording): ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-accent/5 text-accent text-[11px] font-medium">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="6"/></svg>
                    Recorded
                </span>
                <?php endif; ?>
            </div>
            <?php if ($has_recording): ?>
            <div class="px-5 pb-4">
                <div class="bg-gray-50 rounded-lg px-4 py-3 flex items-center gap-3">
                    <audio controls preload="none" class="flex-1 h-8" style="height:32px">
                        <source src="<?= htmlspecialchars($rec_url) ?>" type="audio/mpeg">
                    </audio>
                    <a href="<?= htmlspecialchars($rec_url) ?>" target="_blank" class="text-xs text-gray-400 hover:text-accent transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php elseif ($page === 'ai-agent'): ?>
    <!-- ==================== AI AGENT ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">AI Agent</h2>
        <p class="text-sm text-gray-500 mt-0.5">Configure the AI voice agent for <?= $hotel ? htmlspecialchars($hotel['name']) : 'this property' ?></p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <div class="bg-white rounded-xl border-2 border-accent p-5 relative">
            <div class="absolute top-3 right-3">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-accent text-white text-[10px] font-bold uppercase tracking-wide">Active</span>
            </div>
            <div class="w-12 h-12 bg-accent/10 rounded-xl flex items-center justify-center mb-4">
                <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
            <h3 class="font-bold text-gray-900 mb-1">AI Concierge</h3>
            <p class="text-sm text-gray-500 leading-relaxed">Information-only resort concierge. Provides guests with details about dining, pools, spa, activities, nearby attractions, and resort amenities.</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 border-dashed p-5 opacity-50">
            <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center mb-4">
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <h3 class="font-bold text-gray-400 mb-1">AI Booking Agent</h3>
            <p class="text-sm text-gray-400 leading-relaxed">Handles availability checks and instant confirmations with direct PMS integration.</p>
            <div class="mt-4"><span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-400">Coming Soon</span></div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-900 text-[15px] mb-4">Agent Settings</h3>
        <form method="POST" action="<?= nav_link('ai-agent', $hotel_id) ?>">
            <input type="hidden" name="hotel" value="<?= $hotel_id ?>">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ElevenLabs Voice ID</label>
                    <input type="text" name="voice_id"
                        value="<?= htmlspecialchars($agent_settings['elevenlabs_voice_id'] ?? '') ?>"
                        placeholder="Leave blank to use the server default"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50 font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Language</label>
                    <select name="language" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50">
                        <?php foreach (['en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'zh' => 'Mandarin'] as $code => $label): ?>
                        <option value="<?= $code ?>" <?= ($agent_settings['language'] ?? 'en') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Greeting Message</label>
                    <textarea name="greeting" rows="2"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50 resize-none"
                        placeholder="Hello, thank you for calling. How can I help you?"><?= htmlspecialchars($agent_settings['greeting_message'] ?? '') ?></textarea>
                    <p class="text-[11px] text-gray-400 mt-1">Injected into the AI context at call start — what the bot "remembers" saying after the pre-recorded audio plays.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">System Prompt</label>
                    <textarea name="system_prompt" rows="12"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50 resize-none font-mono text-[12px]"
                        placeholder="You are the AI concierge at..."><?= htmlspecialchars($agent_settings['system_prompt'] ?? '') ?></textarea>
                    <p class="text-[11px] text-gray-400 mt-1">The full LLM system prompt for this property. Knowledge base entries are appended to this at call time.</p>
                </div>
                <button type="submit" class="px-4 py-2 bg-accent text-white text-sm font-medium rounded-lg hover:bg-accent-dark transition">Save Changes</button>
            </div>
        </form>
    </div>

    <?php elseif ($page === 'knowledge'): ?>
    <!-- ==================== KNOWLEDGE BASE ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Knowledge Base</h2>
        <p class="text-sm text-gray-500 mt-0.5">Hotel information your AI agent references on every call</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-100 p-6 mb-6">
        <h3 class="font-semibold text-gray-900 text-[15px] mb-4">Add Entry</h3>

        <form method="POST" enctype="multipart/form-data" action="<?= nav_link('knowledge', $hotel_id) ?>" class="mb-6">
            <input type="hidden" name="hotel" value="<?= $hotel_id ?>">
            <label class="block text-sm font-medium text-gray-700 mb-2">Upload file <span class="text-gray-400 font-normal">(TXT, CSV, MD)</span></label>
            <div class="flex items-center gap-3">
                <input type="file" name="kb_file" accept=".txt,.csv,.md"
                    class="flex-1 text-sm text-gray-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-accent/10 file:text-accent hover:file:bg-accent/20 cursor-pointer">
                <button type="submit" class="px-4 py-1.5 bg-accent text-white text-sm font-medium rounded-lg hover:bg-accent-dark transition whitespace-nowrap">Upload</button>
            </div>
        </form>

        <div class="border-t border-gray-100 pt-5">
            <p class="text-sm font-medium text-gray-700 mb-2">Or paste content directly <span class="text-gray-400 font-normal">(use this for PDF text)</span></p>
            <form method="POST" action="<?= nav_link('knowledge', $hotel_id) ?>">
                <input type="hidden" name="hotel" value="<?= $hotel_id ?>">
                <div class="space-y-3">
                    <input type="text" name="paste_name" placeholder="Source name (e.g. Hotel_Info_2025.pdf)"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50">
                    <textarea name="paste_content" rows="6" placeholder="Paste your hotel information here..."
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50 resize-none font-mono text-[12px]"></textarea>
                    <button type="submit" class="px-4 py-2 bg-accent text-white text-sm font-medium rounded-lg hover:bg-accent-dark transition">Add Entry</button>
                </div>
            </form>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-[15px]">Uploaded Documents</h3>
            <span class="text-xs text-gray-400"><?= count($kb_entries) ?> <?= count($kb_entries) === 1 ? 'entry' : 'entries' ?></span>
        </div>
        <?php if (empty($kb_entries)): ?>
        <div class="px-5 py-10 text-center text-gray-400 text-sm">No knowledge entries yet. Upload a file or paste content above.</div>
        <?php else: ?>
        <div class="divide-y divide-gray-50">
            <?php foreach ($kb_entries as $entry):
                $ext         = strtolower(pathinfo($entry['source_name'], PATHINFO_EXTENSION));
                $icon_colors = ['pdf' => 'bg-red-50 text-red-500', 'csv' => 'bg-green-50 text-green-600', 'md' => 'bg-blue-50 text-blue-500'];
                $icon_cls    = $icon_colors[$ext] ?? 'bg-gray-50 text-gray-500';
            ?>
            <div class="px-5 py-3.5 flex items-center gap-3 hover:bg-gray-50/60">
                <div class="w-9 h-9 <?= $icon_cls ?> rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-[10px] font-bold"><?= strtoupper($ext ?: 'TXT') ?></span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?= htmlspecialchars($entry['source_name']) ?></p>
                    <p class="text-[11px] text-gray-400"><?= number_format(strlen($entry['content'])) ?> chars &middot; Added <?= date('M j, Y', strtotime($entry['created_at'])) ?></p>
                </div>
                <form method="POST" action="<?= nav_link('knowledge', $hotel_id) ?>" onsubmit="return confirm('Delete this entry?')">
                    <input type="hidden" name="hotel" value="<?= $hotel_id ?>">
                    <input type="hidden" name="delete_entry" value="<?= $entry['id'] ?>">
                    <button type="submit" class="p-1.5 text-gray-400 hover:text-red-500 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php elseif ($page === 'phone'): ?>
    <!-- ==================== PHONE NUMBERS ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Phone Numbers</h2>
        <p class="text-sm text-gray-500 mt-0.5">Manage phone lines connected to the AI agent</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 text-[15px]">Active Numbers</h3>
        </div>
        <div class="divide-y divide-gray-50">
            <?php if (empty($hotel_numbers)): ?>
            <div class="px-5 py-10 text-center text-gray-400 text-sm">No phone numbers assigned to this property yet.</div>
            <?php else: ?>
            <?php foreach ($hotel_numbers as $num): ?>
            <div class="px-5 py-4 flex items-center gap-4">
                <div class="w-10 h-10 bg-emerald-50 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($num['twilio_number']) ?></p>
                    <p class="text-xs text-gray-400"><?= $num['label'] ? htmlspecialchars($num['label']) : 'No label' ?> &middot; <?= $num['ai_enabled'] ? 'AI routing active' : 'Passing to PBX' ?></p>
                </div>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium <?= $num['ai_enabled'] ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' ?>">
                    <span class="w-1.5 h-1.5 rounded-full <?= $num['ai_enabled'] ? 'bg-emerald-400' : 'bg-gray-400' ?>"></span>
                    <?= $num['ai_enabled'] ? 'AI Active' : 'AI Off' ?>
                </span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($hotel_numbers)): ?>
    <div class="bg-white rounded-xl border border-gray-100 mt-4 p-5">
        <h3 class="font-semibold text-gray-900 text-[15px] mb-4">Routing Configuration</h3>
        <?php foreach ($hotel_numbers as $idx => $num): ?>
        <div class="grid grid-cols-2 gap-3 text-sm <?= $idx < count($hotel_numbers) - 1 ? 'mb-5 pb-5 border-b border-gray-100' : '' ?>">
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">Phone Number</p>
                <p class="font-mono text-gray-900"><?= htmlspecialchars($num['twilio_number']) ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">Label</p>
                <p class="text-gray-900"><?= htmlspecialchars($num['label'] ?: '—') ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">AI Webhook</p>
                <p class="font-mono text-gray-700 text-xs break-all"><?= htmlspecialchars(BOT_WEBHOOK_URL ?: 'Not configured') ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">PBX Fallback</p>
                <p class="font-mono text-gray-700 text-xs break-all"><?= htmlspecialchars($num['original_voice_url'] ?: 'Not saved yet — toggle AI on first') ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php elseif ($page === 'properties'): ?>
    <!-- ==================== PROPERTIES ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Properties</h2>
        <p class="text-sm text-gray-500 mt-0.5">Manage all hotel properties on this platform</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-100 p-5 mb-6">
        <h3 class="font-semibold text-gray-900 text-[15px] mb-4">Add New Property</h3>
        <form method="POST" action="?page=properties">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Hotel Name</label>
                    <input type="text" name="hotel_name" required placeholder="e.g. Sheraton Grand Sydney"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Identifier <span class="text-gray-400 font-normal">(unique slug)</span></label>
                    <input type="text" name="hotel_slug" required placeholder="e.g. sheraton-grand-sydney"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50 font-mono">
                    <p class="text-[11px] text-gray-400 mt-1">Lowercase letters, numbers, and hyphens only.</p>
                </div>
            </div>
            <button type="submit" class="px-4 py-2 bg-accent text-white text-sm font-medium rounded-lg hover:bg-accent-dark transition">Add Property</button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-[15px]">All Properties</h3>
            <span class="text-xs text-gray-400"><?= count($all_hotels_for_props) ?> total</span>
        </div>
        <div class="divide-y divide-gray-50">
            <?php foreach ($all_hotels_for_props as $h): ?>
            <div class="px-5 py-4 flex items-center gap-4 hover:bg-gray-50/60">
                <div class="w-9 h-9 bg-accent/10 rounded-lg flex items-center justify-center text-[11px] font-bold text-accent-light">
                    <?= strtoupper(substr($h['name'], 0, 2)) ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($h['name']) ?></p>
                    <p class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($h['slug']) ?> &middot; <?= $h['number_count'] ?> number<?= $h['number_count'] != 1 ? 's' : '' ?></p>
                </div>
                <a href="?page=dashboard&hotel=<?= $h['id'] ?>" class="text-xs text-accent hover:text-accent-dark font-medium">Open &rarr;</a>
            </div>
            <?php endforeach; ?>
            <?php if (empty($all_hotels_for_props)): ?>
            <div class="px-5 py-10 text-center text-gray-400 text-sm">No properties yet. Add one above.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    </div>
</main>

</body>
</html>
