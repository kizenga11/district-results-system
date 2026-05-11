<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

// ── SVG icon helper ────────────────────────────────────────────
function icon(string $name): string
{
    $icons = [
        'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5V6a.5.5 0 0 1-1 0V4.5A.5.5 0 0 1 8 4M3.732 5.732a.5.5 0 0 1 .707 0l.915.914a.5.5 0 1 1-.708.708l-.914-.915a.5.5 0 0 1 0-.707M2 10a.5.5 0 0 1 .5-.5h1.586a.5.5 0 0 1 0 1H2.5A.5.5 0 0 1 2 10m9.5 0a.5.5 0 0 1 .5-.5h1.5a.5.5 0 0 1 0 1H12a.5.5 0 0 1-.5-.5m.754-4.246a.39.39 0 0 0-.527-.02L7.547 9.31a.91.91 0 1 0 1.302 1.258l3.434-4.297a.39.39 0 0 0-.029-.518z"/><path fill-rule="evenodd" d="M0 10a8 8 0 1 1 15.547 2.661c-.442 1.253-1.845 1.602-2.932 1.25C11.309 13.488 9.475 13 8 13c-1.474 0-3.31.488-4.615.911-1.087.352-2.49.003-2.932-1.25A8 8 0 0 1 0 10"/></svg>',
        'users'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M15 14s1 0 1-1-1-4-5-4-5 3-5 4 1 1 1 1zm-7.978-1L7 12.996c.001-.264.167-1.03.76-1.72C8.312 10.629 9.282 10 11 10c1.717 0 2.687.63 3.24 1.276.593.69.758 1.457.76 1.72l-.008.002-.014.002zM11 7a2 2 0 1 0 0-4 2 2 0 0 0 0 4m3-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0M6.936 9.28a6 6 0 0 0-1.23-.247A7 7 0 0 0 5 9c-4 0-5 3-5 4q0 1 1 1h4.216A2.24 2.24 0 0 1 5 13c0-1.01.377-2.042 1.09-2.904.243-.294.526-.569.846-.816M4.92 10A5.5 5.5 0 0 0 4 13H1c0-.26.164-1.03.76-1.724.545-.636 1.492-1.256 3.16-1.275ZM1.5 5.5a3 3 0 1 1 6 0 3 3 0 0 1-6 0m3-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4"/></svg>',
        'school'    => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8.211 2.047a.5.5 0 0 0-.422 0l-7.5 3.5a.5.5 0 0 0 .025.917l7.5 3a.5.5 0 0 0 .372 0L14 7.14V13a1 1 0 0 0-1 1v2h3v-2a1 1 0 0 0-1-1V6.739l.686-.275a.5.5 0 0 0 .025-.917zM8 8.46 1.758 5.965 8 3.052l6.242 2.913z"/><path d="M4.176 9.032a.5.5 0 0 0-.656.327l-.5 1.7a.5.5 0 0 0 .294.605l4.5 1.8a.5.5 0 0 0 .372 0l4.5-1.8a.5.5 0 0 0 .294-.605l-.5-1.7a.5.5 0 0 0-.656-.327L8 10.466z"/></svg>',
        'subjects'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1 2.828c.885-.37 2.154-.769 3.388-.893 1.33-.134 2.458.063 3.112.752v9.746c-.935-.53-2.12-.603-3.213-.493-1.18.12-2.37.461-3.287.811zm7.5-.141c.654-.689 1.782-.886 3.112-.752 1.234.124 2.503.523 3.388.893v9.923c-.918-.35-2.107-.692-3.287-.81-1.094-.11-2.278-.039-3.213.492zM8 1.783C7.015.936 5.587.81 4.287.94c-1.514.153-3.042.672-3.994 1.105A.5.5 0 0 0 0 2.5v11a.5.5 0 0 0 .707.455c.882-.4 2.303-.881 3.68-1.02 1.409-.142 2.59.087 3.223.877a.5.5 0 0 0 .78 0c.633-.79 1.814-1.019 3.222-.877 1.378.139 2.8.62 3.681 1.02A.5.5 0 0 0 16 13.5v-11a.5.5 0 0 0-.293-.455c-.952-.433-2.48-.952-3.994-1.105C10.413.809 8.985.936 8 1.783"/></svg>',
        'exam'      => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5 10.5a.5.5 0 0 1 .5-.5h2a.5.5 0 0 1 0 1h-2a.5.5 0 0 1-.5-.5m0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5m0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5m0-2a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5"/><path d="M3 0h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2m0 1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1z"/></svg>',
        'assign'    => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M6 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5 6s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1zM11 3.5a.5.5 0 0 1 .5-.5h4a.5.5 0 0 1 0 1h-4a.5.5 0 0 1-.5-.5m.5 2.5a.5.5 0 0 0 0 1h4a.5.5 0 0 0 0-1zm2 3a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1zm0 3a.5.5 0 0 0 0 1h2a.5.5 0 0 0 0-1z"/></svg>',
        'students'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M7 14s-1 0-1-1 1-4 5-4 5 3 5 4-1 1-1 1zm4-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6m-5.784 6A2.24 2.24 0 0 1 5 13c0-1.355.68-2.75 1.936-3.72A6.3 6.3 0 0 0 5 9c-4 0-5 3-5 4s1 1 1 1zM4.5 8a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5"/></svg>',
        'marks'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M2.5 3a.5.5 0 0 0 0 1h11a.5.5 0 0 0 0-1zm2 4a.5.5 0 0 0 0 1h7a.5.5 0 0 0 0-1zm2 4a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1z"/><path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm15 0a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1z"/></svg>',
        'report'    => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M4 11H2v3h2zm5-4H7v7h2zm5-5v12h-2V2zm-2-1a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h2a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM6 7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm-5 4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1z"/></svg>',
        'process'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492M5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0"/><path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.475l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z"/></svg>',
        'logout'    => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/><path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/></svg>',
        'profile'   => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/></svg>',
        'bell'      => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/></svg>',
        'chevron'   => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708"/></svg>',
        'combo'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1 2a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1zM1 7a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1zM1 12a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1zm5 0a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function render_header(string $title): void
{
    $user = current_user();
    $role = $user['role'] ?? '';

    // ── Notification data (cached in session for 30s) ─────────
    $unread_count  = 0;
    $recent_notifs = [];
    if ($user) {
        $uid    = (int)$user['id'];
        $cached = $_SESSION['notif_cache'] ?? [];
        $now    = time();

        if (($cached['ts'] ?? 0) > $now - 30 && ($cached['uid'] ?? 0) === $uid) {
            $unread_count  = $cached['unread'];
            $recent_notifs = $cached['recent'];
        } else {
            $unread_count  = notify_unread_count($uid);
            $recent_notifs = notify_recent($uid, 5);
            $_SESSION['notif_cache'] = [
                'uid'    => $uid,
                'ts'     => $now,
                'unread' => $unread_count,
                'recent' => $recent_notifs,
            ];
        }

        // Auto-check notifications once per day
        $today = date('Y-m-d');
        if (($_SESSION['notif_auto_checked'] ?? '') !== $today) {
            $_SESSION['notif_auto_checked'] = $today;
            if ($role === 'teacher') {
                notify_check_topic_overdue($uid);
            }
            if ($role === 'headmaster') {
                notify_check_school_performance($uid, (int)($user['school_id'] ?? 0));
            }
            // Refresh cache after auto-check
            $unread_count  = notify_unread_count($uid);
            $recent_notifs = notify_recent($uid, 5);
            $_SESSION['notif_cache'] = [
                'uid'    => $uid,
                'ts'     => $now,
                'unread' => $unread_count,
                'recent' => $recent_notifs,
            ];
        }
    }

    // ── Detect current page for auto-expanding groups ─────────────
    $req_uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    $base_prefix  = rtrim(BASE_PATH, '/') . '/';
    $current_page = str_starts_with($req_uri, $base_prefix)
                  ? substr($req_uri, strlen($base_prefix))
                  : ltrim($req_uri, '/');
    $current_page = strtok($current_page, '?') ?: '';

    // Build nav items per role — supports flat links and groups
    $nav_items = [];

    $nav_items[] = ['url' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'dashboard'];

    if (in_array($role, ['super_admin', 'district_admin'], true)) {
        $admin_children = [];
        if ($role === 'super_admin') {
            $admin_children[] = ['url' => 'super/users.php',          'label' => 'Users',        'icon' => 'users'];
        }
        $admin_children[] = ['url' => 'district/schools.php',         'label' => 'Schools',      'icon' => 'school'];
        $admin_children[] = ['url' => 'district/headmasters.php',     'label' => 'Headmasters',  'icon' => 'users'];
        $admin_children[] = ['url' => 'district/subjects.php',        'label' => 'Subjects',     'icon' => 'subjects'];
        $admin_children[] = ['url' => 'district/alevel_combinations.php', 'label' => 'Combinations', 'icon' => 'combo'];
        $admin_children[] = ['url' => 'district/exams.php',           'label' => 'Exams',        'icon' => 'exam'];

        $nav_items[] = [
            'group'    => $role === 'super_admin' ? 'Usimamizi' : 'Usimamizi wa Wilaya',
            'icon'     => 'school',
            'children' => $admin_children,
        ];

        $nav_items[] = [
            'group'    => 'Ripoti',
            'icon'     => 'report',
            'children' => [
                ['url' => 'school/exam_results.php',               'label' => 'Matokeo ya Mtihani',    'icon' => 'report'],
                ['url' => 'school/exam_analysis.php',              'label' => 'Uchambuzi wa Shule',    'icon' => 'report'],
                ['url' => 'district/exam_analysis.php',            'label' => 'Uchambuzi wa Wilaya',   'icon' => 'report'],
                ['url' => 'district/teaching_progress_report.php', 'label' => 'Maendeleo ya Mafunzo',  'icon' => 'process'],
            ],
        ];
    }

    if ($role === 'headmaster') {
        $nav_items[] = [
            'group'    => 'Wanafunzi',
            'icon'     => 'students',
            'children' => [
                ['url' => 'school/students.php',             'label' => 'Wanafunzi',         'icon' => 'students'],
                ['url' => 'school/student_subjects.php',     'label' => 'Panga Masomo',       'icon' => 'subjects'],
                ['url' => 'school/student_combinations.php', 'label' => 'Panga Combinations', 'icon' => 'combo'],
            ],
        ];
        $nav_items[] = [
            'group'    => 'Walimu & Masomo',
            'icon'     => 'users',
            'children' => [
                ['url' => 'school/teachers.php',            'label' => 'Walimu',       'icon' => 'users'],
                ['url' => 'school/subjects.php',            'label' => 'Masomo',       'icon' => 'subjects'],
                ['url' => 'school/alevel_combinations.php', 'label' => 'Combinations', 'icon' => 'combo'],
                ['url' => 'school/assignments.php',         'label' => 'Assignments',  'icon' => 'assign'],
            ],
        ];
        $nav_items[] = [
            'group'    => 'Mitihani',
            'icon'     => 'exam',
            'children' => [
                ['url' => 'teacher/marks_entry.php',  'label' => 'Ingiza Alama',       'icon' => 'marks'],
                ['url' => 'school/exam_results.php',  'label' => 'Matokeo ya Mtihani', 'icon' => 'report'],
                ['url' => 'school/exam_analysis.php', 'label' => 'Uchambuzi',          'icon' => 'report'],
            ],
        ];
        $nav_items[] = [
            'group'    => 'Maendeleo ya Mafunzo',
            'icon'     => 'process',
            'children' => [
                ['url' => 'school/topic_test_approvals.php', 'label' => 'Idhibiti Majaribio', 'icon' => 'exam'],
                ['url' => 'school/topic_analysis.php',       'label' => 'Uchambuzi wa Mada',  'icon' => 'report'],
            ],
        ];
    }

    if ($role === 'teacher') {
        $nav_items[] = [
            'group'    => 'Kazi Yangu',
            'icon'     => 'marks',
            'children' => [
                ['url' => 'teacher/marks_entry.php',       'label' => 'Ingiza Alama',       'icon' => 'marks'],
                ['url' => 'teacher/teaching_progress.php', 'label' => 'Maendeleo ya Mafunzo','icon' => 'process'],
                ['url' => 'school/exam_analysis.php',      'label' => 'Uchambuzi wa Mtihani','icon' => 'report'],
            ],
        ];
    }

    $flashSuccess = flash_get('success');
    $flashError   = flash_get('error');

    // ── Build sidebar HTML with collapsible groups ─────────────────
    $group_counter = 0;
    $sidebar_links = '';

    foreach ($nav_items as $item) {
        if (isset($item['group'])) {
            $group_counter++;
            $gid = 'navGroup' . $group_counter;

            // Check if any child is the current page → auto-expand
            $is_active = false;
            foreach ($item['children'] as $child) {
                if ($child['url'] === $current_page) { $is_active = true; break; }
            }
            $show_class    = $is_active ? ' show'   : '';
            $toggle_class  = $is_active ? ' active' : '';

            $sidebar_links .= '<div class="sidebar-group">';
            $sidebar_links .= '<button class="sidebar-group-toggle' . $toggle_class . '" '
                . 'data-bs-toggle="collapse" data-bs-target="#' . $gid . '" '
                . 'aria-expanded="' . ($is_active ? 'true' : 'false') . '">';
            $sidebar_links .= '<span class="sg-icon">' . icon($item['icon']) . '</span>';
            $sidebar_links .= '<span class="sg-label">' . e($item['group']) . '</span>';
            $sidebar_links .= '<span class="sg-chevron">' . icon('chevron') . '</span>';
            $sidebar_links .= '</button>';

            $sidebar_links .= '<div class="collapse sidebar-group-body' . $show_class . '" id="' . $gid . '">';
            foreach ($item['children'] as $child) {
                $href      = e(url($child['url']));
                $is_cur    = ($child['url'] === $current_page) ? ' current' : '';
                $sidebar_links .= '<a class="sidebar-link sidebar-sublink' . $is_cur . '" href="' . $href . '">'
                    . icon($child['icon'])
                    . '<span>' . e($child['label']) . '</span>'
                    . '</a>';
            }
            $sidebar_links .= '</div>';
            $sidebar_links .= '</div>';

        } else {
            // Flat link (Dashboard)
            $href   = e(url($item['url']));
            $is_cur = ($item['url'] === $current_page) ? ' current' : '';
            $sidebar_links .= '<a class="sidebar-link' . $is_cur . '" href="' . $href . '">'
                . icon($item['icon'])
                . '<span>' . e($item['label']) . '</span>'
                . '</a>';
        }
    }

    // Avatar initial
    $initial = $user ? strtoupper(mb_substr($user['full_name'], 0, 1)) : '?';
    $role_label = str_replace('_', ' ', $role);

    $flash_html = '';
    if ($flashSuccess) {
        $flash_html .= '<div class="flash-toast flash-success"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg><span>' . e($flashSuccess) . '</span></div>';
    }
    if ($flashError) {
        $flash_html .= '<div class="flash-toast flash-error"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293 5.354 4.646z"/></svg><span>' . e($flashError) . '</span></div>';
    }

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e(APP_NAME . ' — ' . $title) . '</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="' . e(url('assets/css/app.css')) . '" rel="stylesheet">';
    echo '</head>';
    echo '<body>';

    if ($user) {
        // Topbar
        echo '<header class="topbar">';
        echo '<button class="btn-topbar-toggle" id="sidebarToggle" aria-label="Menu">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5"/></svg>';
        echo '</button>';
        echo '<a class="topbar-brand" href="' . e(url('dashboard.php')) . '">' . e(APP_NAME) . '</a>';
        echo '<div class="topbar-spacer"></div>';

        // ── Notification Bell Dropdown ──────────────────────
        $dot_map = ['success' => '#22c55e', 'danger' => '#ef4444', 'warning' => '#f59e0b', 'info' => '#3b82f6', 'secondary' => '#94a3b8'];
        echo '<div class="dropdown">';
        echo '<button class="btn-notif dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false" title="Notifications">';
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6c0-2.42 1.72-4.44 4.005-4.901a1 1 0 1 1 1.99 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/></svg>';
        if ($unread_count > 0) {
            echo '<span class="notif-badge">' . min($unread_count, 99) . '</span>';
        }
        echo '</button>';

        // Notification dropdown menu
        echo '<div class="dropdown-menu dropdown-menu-end notif-dropdown">';
        echo '<div class="notif-dropdown-header"><span>Notifications</span>';
        if ($unread_count > 0) {
            echo '<span class="badge bg-danger rounded-pill" style="font-size:.65rem">' . $unread_count . '</span>';
        }
        echo '</div>';

        if (empty($recent_notifs)) {
            echo '<div style="padding:1.5rem 1rem;text-align:center;color:#94a3b8;font-size:.82rem">No new notifications</div>';
        } else {
            foreach ($recent_notifs as $rn) {
                $rncfg  = notify_type_config($rn['type']);
                $rndot  = $dot_map[$rncfg['color']] ?? '#94a3b8';
                $rnclass = 'notif-item' . (!$rn['is_read'] ? ' unread' : '');
                $rnhref  = e(url('notifications.php?read=' . (int)$rn['id']));
                $rnmsg   = mb_strlen($rn['message']) > 65 ? mb_substr($rn['message'], 0, 65) . '…' : $rn['message'];
                echo '<a class="' . $rnclass . '" href="' . $rnhref . '">';
                echo '<div class="notif-dot" style="background:' . $rndot . '"></div>';
                echo '<div class="notif-body">';
                echo '<div class="notif-title">' . e($rn['title']) . '</div>';
                echo '<div class="notif-msg">' . e($rnmsg) . '</div>';
                echo '<div class="notif-time">' . e(notify_time_ago($rn['created_at'])) . '</div>';
                echo '</div>';
                echo '</a>';
            }
        }

        echo '<div class="notif-dropdown-footer">';
        echo '<a href="' . e(url('notifications.php')) . '">View All Notifications</a>';
        echo '</div>';
        echo '</div>'; // end .notif-dropdown
        echo '</div>'; // end .dropdown

        // ── User info (link kwenda profili) ─────────────────
        echo '<a class="topbar-user" href="' . e(url('profile.php')) . '">';
        echo '<div class="avatar">' . e($initial) . '</div>';
        echo '<div class="user-info">';
        echo '<div class="user-name">' . e($user['full_name']) . '</div>';
        echo '<div class="user-role">' . e($role_label) . '</div>';
        echo '</div>';
        echo '</a>';

        echo '</header>';

        // Overlay
        echo '<div class="sidebar-overlay" id="sidebarOverlay"></div>';

        // Sidebar
        echo '<aside class="sidebar" id="sidebar">';
        echo '<div class="sidebar-section">';
        echo $sidebar_links;
        echo '</div>';
        echo '<div class="sidebar-footer">';
        // Notifications link with badge
        $sb_notif_href = e(url('notifications.php'));
        echo '<a class="sidebar-link" href="' . $sb_notif_href . '" style="position:relative">';
        echo icon('bell') . '<span>Notifications</span>';
        if ($unread_count > 0) {
            echo '<span class="sidebar-notif-badge">' . min($unread_count, 99) . '</span>';
        }
        echo '</a>';
        // Profili link
        echo '<a class="sidebar-link" href="' . e(url('profile.php')) . '">' . icon('profile') . '<span>Profile</span></a>';
        echo '<a class="sidebar-link" href="' . e(url('logout.php')) . '">' . icon('logout') . '<span>Logout</span></a>';
        echo '</div>';
        echo '</aside>';

        // Main
        echo '<div class="main-wrap">';
        echo '<div class="main-content">';
        echo $flash_html;
    } else {
        // Auth pages (no sidebar)
        echo '<div>';
        echo $flash_html;
    }
}

function render_footer(): void
{
    echo '</div></div>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
    echo '<script src="' . e(url('assets/js/app.js')) . '"></script>';
    echo '</body></html>';
}
