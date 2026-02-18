<?php
require_once __DIR__ . '/config.php';

// --- Twilio API helper ---
function twilio_api($endpoint, $method = 'GET', $data = null) {
    $url = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . $endpoint;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
        CURLOPT_TIMEOUT => 15,
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

// --- Handle AI toggle ---
$toast = '';
$toast_type = '';
if (isset($_POST['toggle_ai'])) {
    $enable = $_POST['toggle_ai'] === 'on';
    $voice_url = $enable ? BOT_WEBHOOK_URL : '';
    twilio_api('/IncomingPhoneNumbers/' . TWILIO_NUMBER_SID . '.json', 'POST', [
        'VoiceUrl' => $voice_url,
    ]);
    $toast = $enable ? 'AI Concierge activated successfully' : 'AI Concierge deactivated';
    $toast_type = $enable ? 'success' : 'warning';
}

// --- Fetch phone number status ---
$number_info = twilio_api('/IncomingPhoneNumbers/' . TWILIO_NUMBER_SID . '.json');
$ai_active = false;
if ($number_info['code'] === 200 && !empty($number_info['body']['voice_url'])) {
    $ai_active = strpos($number_info['body']['voice_url'], 'ngrok') !== false
              || strpos($number_info['body']['voice_url'], BOT_WEBHOOK_URL) !== false;
}

// --- Determine active page ---
$page = $_GET['page'] ?? 'dashboard';

// --- Fetch call data (for dashboard & transcripts pages) ---
$all_calls = [];
$recordings = [];
if (in_array($page, ['dashboard', 'transcripts'])) {
    $calls_data = twilio_api('/Calls.json?To=' . urlencode(TWILIO_NUMBER) . '&PageSize=50');
    $all_calls = $calls_data['code'] === 200 ? ($calls_data['body']['calls'] ?? []) : [];

    // Fetch recordings
    $rec_data = twilio_api('/Recordings.json?PageSize=50');
    $raw_recs = $rec_data['code'] === 200 ? ($rec_data['body']['recordings'] ?? []) : [];
    // Index recordings by call SID
    foreach ($raw_recs as $r) {
        $recordings[$r['call_sid']][] = $r;
    }
}

// Stats
$total_calls = count($all_calls);
$total_duration = 0;
$answered = 0;
foreach ($all_calls as $c) {
    if ((int)$c['duration'] > 0) {
        $total_duration += (int)$c['duration'];
        $answered++;
    }
}
$avg_duration = $answered > 0 ? round($total_duration / $answered) : 0;
$today_count = 0;
$today = date('Y-m-d');
foreach ($all_calls as $c) {
    if (substr($c['date_created'], 0, 16) && strpos(date('Y-m-d', strtotime($c['date_created'])), $today) === 0) {
        $today_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PBX AI - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sidebar: { DEFAULT:'#0f172a', light:'#1e293b' },
                        accent: { DEFAULT:'#6366f1', light:'#818cf8', dark:'#4f46e5' },
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

    <!-- Hotel selector -->
    <div class="px-4 py-4 border-b border-white/5">
        <label class="text-[10px] uppercase tracking-widest text-slate-500 font-semibold mb-2 block">Property</label>
        <div class="bg-sidebar-light rounded-lg px-3 py-2.5 flex items-center gap-2 cursor-pointer hover:bg-slate-700 transition">
            <div class="w-7 h-7 bg-accent/20 rounded-md flex items-center justify-center text-[11px] font-bold text-accent-light">JW</div>
            <div class="flex-1 min-w-0">
                <p class="text-white text-[13px] font-medium truncate"><?= HOTEL_NAME ?></p>
                <p class="text-slate-500 text-[11px]"><?= TWILIO_NUMBER ?></p>
            </div>
            <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/></svg>
        </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto scrollbar-thin">
        <p class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold px-3 mb-2">Main</p>
        <a href="?page=dashboard" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'dashboard' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            Dashboard
        </a>
        <a href="?page=transcripts" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'transcripts' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Transcripts
        </a>

        <p class="text-[10px] uppercase tracking-widest text-slate-600 font-semibold px-3 mb-2 mt-6">Configure</p>
        <a href="?page=ai-agent" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'ai-agent' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            AI Agent
        </a>
        <a href="?page=knowledge" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'knowledge' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Knowledge Base
        </a>
        <a href="?page=phone" class="flex items-center gap-3 px-3 py-2 rounded-lg text-[13px] <?= $page === 'phone' ? 'bg-accent/10 text-accent-light font-medium' : 'text-slate-400 hover:text-white hover:bg-white/5' ?> transition">
            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            Phone Numbers
        </a>
    </nav>

    <!-- Bottom: AI status + toggle -->
    <div class="px-4 py-4 border-t border-white/5">
        <form method="POST">
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
    </div>
</aside>

<!-- Main Content -->
<main class="ml-[260px] flex-1 min-h-screen">

    <!-- Top bar -->
    <header class="h-14 border-b border-gray-200 bg-white flex items-center justify-between px-6 sticky top-0 z-40">
        <div>
            <?php if ($toast): ?>
            <div class="flex items-center gap-2 text-sm <?= $toast_type === 'success' ? 'text-emerald-600' : 'text-amber-600' ?>">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <?= htmlspecialchars($toast) ?>
            </div>
            <?php else: ?>
            <p class="text-sm text-gray-500"><?= date('l, F j, Y') ?></p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 bg-accent rounded-full flex items-center justify-center text-white text-xs font-bold">H</div>
        </div>
    </header>

    <div class="p-6">

    <?php if ($page === 'dashboard'): ?>
    <!-- ==================== DASHBOARD ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Dashboard</h2>
        <p class="text-sm text-gray-500 mt-0.5">Overview of your AI concierge activity</p>
    </div>

    <!-- Stats -->
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
                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Total Calls</p>
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

    <!-- Recent Calls -->
    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-[15px]">Recent Calls</h3>
            <a href="?page=transcripts" class="text-xs text-accent hover:text-accent-dark font-medium">View all &rarr;</a>
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
                <?php foreach (array_slice($all_calls, 0, 8) as $call): ?>
                <?php
                    $from = $call['from_formatted'] ?? $call['from'] ?? 'Unknown';
                    $date = date('M j, g:i A', strtotime($call['date_created']));
                    $dur = (int)($call['duration'] ?? 0);
                    $dur_fmt = $dur > 0 ? gmdate($dur >= 3600 ? 'H:i:s' : 'i:s', $dur) : '--:--';
                    $status = $call['status'] ?? 'unknown';
                    $badge = [
                        'completed' => 'bg-emerald-50 text-emerald-600',
                        'in-progress' => 'bg-blue-50 text-blue-600',
                        'ringing' => 'bg-amber-50 text-amber-600',
                        'busy' => 'bg-orange-50 text-orange-600',
                        'no-answer' => 'bg-red-50 text-red-600',
                        'failed' => 'bg-red-50 text-red-600',
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

    <?php elseif ($page === 'transcripts'): ?>
    <!-- ==================== TRANSCRIPTS ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Call Transcripts</h2>
        <p class="text-sm text-gray-500 mt-0.5">Recordings and transcripts of all AI-handled calls</p>
    </div>

    <div class="space-y-3">
        <?php if (empty($all_calls)): ?>
        <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
            <p class="text-gray-400 text-sm">No calls recorded yet. Transcripts will appear here after calls are handled by the AI.</p>
        </div>
        <?php else: ?>
        <?php foreach ($all_calls as $call):
            $call_sid = $call['sid'];
            $from = $call['from_formatted'] ?? $call['from'] ?? 'Unknown';
            $date = date('M j, Y \a\t g:i A', strtotime($call['date_created']));
            $dur = (int)($call['duration'] ?? 0);
            $dur_fmt = $dur > 0 ? gmdate($dur >= 3600 ? 'H:i:s' : 'i:s', $dur) : '--:--';
            $status = $call['status'] ?? 'unknown';
            $has_recording = isset($recordings[$call_sid]);
            $rec = $has_recording ? $recordings[$call_sid][0] : null;
            $rec_url = $rec ? "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Recordings/" . $rec['sid'] . ".mp3" : '';
        ?>
        <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
            <div class="px-5 py-4 flex items-center gap-4">
                <!-- Call icon -->
                <div class="w-10 h-10 rounded-full <?= $status === 'completed' ? 'bg-emerald-50' : 'bg-gray-50' ?> flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 <?= $status === 'completed' ? 'text-emerald-500' : 'text-gray-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                </div>
                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($from) ?></span>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium <?= $status === 'completed' ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' ?>"><?= $status ?></span>
                    </div>
                    <p class="text-xs text-gray-400 mt-0.5"><?= $date ?> &middot; <?= $dur_fmt ?></p>
                </div>
                <!-- Recording badge -->
                <?php if ($has_recording): ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-accent/5 text-accent text-[11px] font-medium">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><circle cx="10" cy="10" r="6"/></svg>
                    Recorded
                </span>
                <?php endif; ?>
            </div>

            <?php if ($has_recording): ?>
            <!-- Audio player -->
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
        <p class="text-sm text-gray-500 mt-0.5">Select and configure your AI voice agent</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        <!-- AI Concierge (selected) -->
        <div class="bg-white rounded-xl border-2 border-accent p-5 relative">
            <div class="absolute top-3 right-3">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-accent text-white text-[10px] font-bold uppercase tracking-wide">Active</span>
            </div>
            <div class="w-12 h-12 bg-accent/10 rounded-xl flex items-center justify-center mb-4">
                <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
            <h3 class="font-bold text-gray-900 mb-1">AI Concierge</h3>
            <p class="text-sm text-gray-500 leading-relaxed">Information-only resort concierge. Provides guests with details about dining, pools, spa, activities, nearby attractions, and resort amenities.</p>
            <div class="mt-4 flex flex-wrap gap-1.5">
                <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">Dining Info</span>
                <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">Pool & Spa</span>
                <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">Activities</span>
                <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">Nearby</span>
                <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-600">Resort Info</span>
            </div>
        </div>

        <!-- Coming soon agents -->
        <div class="bg-white rounded-xl border border-gray-200 border-dashed p-5 opacity-50">
            <div class="w-12 h-12 bg-gray-100 rounded-xl flex items-center justify-center mb-4">
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <h3 class="font-bold text-gray-400 mb-1">AI Booking Agent</h3>
            <p class="text-sm text-gray-400 leading-relaxed">Specialized booking-only agent with direct PMS integration. Handles availability checks and instant confirmations.</p>
            <div class="mt-4">
                <span class="px-2 py-0.5 bg-gray-100 rounded text-[11px] text-gray-400">Coming Soon</span>
            </div>
        </div>
    </div>

    <!-- Agent Settings (visual only) -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="font-semibold text-gray-900 text-[15px] mb-4">Agent Settings</h3>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Voice</label>
                <select class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50">
                    <option selected>Charlotte - Professional Female</option>
                    <option>James - Professional Male</option>
                    <option>Custom Voice</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Language</label>
                <select class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50">
                    <option selected>English</option>
                    <option>Spanish</option>
                    <option>French</option>
                    <option>German</option>
                    <option>Mandarin</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Greeting Message</label>
                <textarea class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-700 bg-gray-50 resize-none" rows="2">Good day, thank you for calling The Grand Azure Hotel. How may I help you?</textarea>
            </div>
            <button class="px-4 py-2 bg-accent text-white text-sm font-medium rounded-lg hover:bg-accent-dark transition">Save Changes</button>
        </div>
    </div>

    <?php elseif ($page === 'knowledge'): ?>
    <!-- ==================== KNOWLEDGE BASE ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Knowledge Base</h2>
        <p class="text-sm text-gray-500 mt-0.5">Upload hotel information for your AI agent to reference</p>
    </div>

    <!-- Upload zone -->
    <div class="bg-white rounded-xl border border-gray-100 p-6 mb-6">
        <div class="border-2 border-dashed border-gray-200 rounded-xl p-10 text-center hover:border-accent/40 transition cursor-pointer">
            <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            <p class="text-sm font-medium text-gray-700 mb-1">Drop files here or click to upload</p>
            <p class="text-xs text-gray-400">PDF, DOCX, TXT, CSV up to 10MB</p>
        </div>
    </div>

    <!-- Existing documents (demo) -->
    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900 text-[15px]">Uploaded Documents</h3>
        </div>
        <div class="divide-y divide-gray-50">
            <div class="px-5 py-3.5 flex items-center gap-3 hover:bg-gray-50/60">
                <div class="w-9 h-9 bg-red-50 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-[11px] font-bold text-red-500">PDF</span>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-900">Hotel_Info_2025.pdf</p>
                    <p class="text-[11px] text-gray-400">2.4 MB &middot; Uploaded Jan 15, 2025</p>
                </div>
                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 text-[10px] font-medium">Processed</span>
            </div>
            <div class="px-5 py-3.5 flex items-center gap-3 hover:bg-gray-50/60">
                <div class="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-[11px] font-bold text-blue-500">DOCX</span>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-900">Room_Types_and_Rates.docx</p>
                    <p class="text-[11px] text-gray-400">890 KB &middot; Uploaded Jan 15, 2025</p>
                </div>
                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 text-[10px] font-medium">Processed</span>
            </div>
            <div class="px-5 py-3.5 flex items-center gap-3 hover:bg-gray-50/60">
                <div class="w-9 h-9 bg-green-50 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-[11px] font-bold text-green-600">CSV</span>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-900">Restaurant_Menu_Spring.csv</p>
                    <p class="text-[11px] text-gray-400">156 KB &middot; Uploaded Feb 1, 2025</p>
                </div>
                <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-600 text-[10px] font-medium">Processed</span>
            </div>
        </div>
    </div>

    <?php elseif ($page === 'phone'): ?>
    <!-- ==================== PHONE NUMBERS ==================== -->
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900">Phone Numbers</h2>
        <p class="text-sm text-gray-500 mt-0.5">Manage phone lines connected to your AI agent</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-100">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900 text-[15px]">Active Numbers</h3>
            <button class="px-3 py-1.5 bg-accent text-white text-xs font-medium rounded-lg hover:bg-accent-dark transition">+ Add Number</button>
        </div>
        <div class="divide-y divide-gray-50">
            <!-- Current number -->
            <div class="px-5 py-4 flex items-center gap-4">
                <div class="w-10 h-10 bg-emerald-50 rounded-full flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-gray-900"><?= TWILIO_NUMBER ?></p>
                    <p class="text-xs text-gray-400">Australia &middot; Assigned to AI Concierge</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium <?= $ai_active ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-100 text-gray-500' ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?= $ai_active ? 'bg-emerald-400' : 'bg-gray-400' ?>"></span>
                        <?= $ai_active ? 'AI Active' : 'AI Off' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Number details -->
    <div class="bg-white rounded-xl border border-gray-100 mt-4 p-5">
        <h3 class="font-semibold text-gray-900 text-[15px] mb-4">Number Configuration</h3>
        <div class="grid grid-cols-2 gap-3 text-sm">
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">Phone Number</p>
                <p class="font-mono text-gray-900"><?= TWILIO_NUMBER ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">AI Agent</p>
                <p class="text-gray-900">AI Concierge</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">Webhook URL</p>
                <p class="font-mono text-gray-700 text-xs break-all"><?= htmlspecialchars($number_info['body']['voice_url'] ?? 'Not configured') ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">Status</p>
                <p class="font-medium <?= $ai_active ? 'text-emerald-600' : 'text-gray-500' ?>"><?= $ai_active ? 'Active' : 'Inactive' ?></p>
            </div>
        </div>
    </div>

    <?php endif; ?>

    </div>
</main>

</body>
</html>
