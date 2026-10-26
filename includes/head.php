<?php
// includes/head.php
// Global head tags, fonts, Tailwind config, and theme initialization
$pageTitle = $pageTitle ?? 'Asterisko - Inventory Management System';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="icon" type="image/png" href="../assets/logo.png">

<!-- Fonts: Plus Jakarta Sans -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Tailwind CSS CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    asterisko: {
                        navy: '#0C2340',
                        'navy-dark': '#071526',
                        'navy-light': '#132E52',
                        'navy-border': '#15345C',
                        orange: '#F28C28',
                        'orange-hover': '#E07B18',
                        'orange-light': '#FFF0DB',
                        canvas: '#F4F7FB'
                    }
                },
                fontFamily: {
                    sans: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif']
                }
            }
        }
    };

    // Immediate Dark Mode initialization
    try {
        const savedTheme = localStorage.getItem('theme');
        const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (savedTheme === 'dark' || (!savedTheme && systemPrefersDark)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    } catch (e) {}
</script>

<!-- Asterisko Enhanced CSS -->
<link rel="stylesheet" href="../assets/app-enhancements.css">
