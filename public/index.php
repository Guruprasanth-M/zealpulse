<?php
// Fallback landing (the '/' route in route/phase1.php wins; this serves /index.php).
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
echo "<h1>ZealPulse</h1><p>See <a href='/'>/</a>.</p>";
