<?php
/**
 * CraftHub Organizer — Save Generated Images to Assets
 */
$targetDirPackages = __DIR__ . '/assets/images/packages';
$targetDirImages   = __DIR__ . '/assets/images';

if (!is_dir($targetDirPackages)) {
    mkdir($targetDirPackages, 0777, true);
}
if (!is_dir($targetDirImages)) {
    mkdir($targetDirImages, 0777, true);
}

$artifactsDir = 'C:\Users\Mary Grace Cadenas\.gemini\antigravity-ide\brain\19ced39b-af6e-44f8-89df-036a7c7170a4';

$sources = [
    'wedding.png'     => $artifactsDir . '\wedding_package_1785430162570.png',
    'birthday.png'    => $artifactsDir . '\birthday_package_1785430180259.png',
    'debut.png'       => $artifactsDir . '\debut_package_1785430194817.png',
    'christening.png' => $artifactsDir . '\christening_package_1785430209645.png',
];

$copied = 0;
foreach ($sources as $filename => $srcPath) {
    if (file_exists($srcPath)) {
        // Save into assets/images/packages/
        copy($srcPath, $targetDirPackages . '/' . $filename);
        // Save into assets/images/
        copy($srcPath, $targetDirImages . '/' . $filename);
        $copied++;
    } else {
        // Search by prefix glob if file timestamp shifted
        $prefix = explode('.', $filename)[0];
        $matches = glob($artifactsDir . '/' . $prefix . '_package_*.png');
        if (!empty($matches)) {
            copy($matches[0], $targetDirPackages . '/' . $filename);
            copy($matches[0], $targetDirImages . '/' . $filename);
            $copied++;
        }
    }
}

return $copied;
