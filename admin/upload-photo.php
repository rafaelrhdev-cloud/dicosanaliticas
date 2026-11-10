<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('gallery.edit', true);

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 422): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Método no permitido.', 405);
}

$slot = (int)($_POST['slot'] ?? 0);
if ($slot < 1 || $slot > 6) {
    fail('Espacio de galería inválido.');
}

$upErr = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
    fail('La foto excede el tamaño que permite el servidor. Prueba con una más ligera.');
}
if (empty($_FILES['photo']) || $upErr !== UPLOAD_ERR_OK) {
    fail('No se recibió ninguna imagen.');
}

$file = $_FILES['photo'];

// 15MB máximo (el navegador ya las reduce antes de subirlas; aquí se vuelven a optimizar).
if ($file['size'] > 15 * 1024 * 1024) {
    fail('La imagen pesa demasiado (máximo 15MB).');
}

// Evita imágenes gigantes que agoten la memoria al procesarlas.
if (($imgDims = @getimagesize($file['tmp_name'])) && $imgDims[0] * $imgDims[1] > 60000000) {
    fail('La imagen tiene demasiados píxeles. Reduce su tamaño y vuelve a intentar.');
}

// Verifica el tipo real del archivo (no solo la extensión, por seguridad).
$imageInfo = @getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    fail('El archivo no es una imagen válida.');
}

$mime = $imageInfo['mime'];
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    fail('Formato no permitido. Usa JPG, PNG o WEBP.');
}

if (!extension_loaded('gd')) {
    fail('El servidor no tiene la librería GD habilitada para procesar imágenes.', 500);
}

// Carga la imagen según su tipo real.
switch ($mime) {
    case 'image/jpeg':
        $image = imagecreatefromjpeg($file['tmp_name']);
        break;
    case 'image/png':
        $image = imagecreatefrompng($file['tmp_name']);
        break;
    case 'image/webp':
        $image = imagecreatefromwebp($file['tmp_name']);
        break;
    default:
        $image = false;
}

if ($image === false) {
    fail('No se pudo procesar la imagen.', 500);
}

// Los celulares guardan la foto "de lado" y marcan la orientación en el EXIF: se corrige aquí.
if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
    $exif = @exif_read_data($file['tmp_name']);
    $orient = (int)($exif['Orientation'] ?? 1);
    $angle = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
    if ($angle !== 0) {
        $rot = imagerotate($image, $angle, 0);
        if ($rot !== false) { imagedestroy($image); $image = $rot; }
    }
}

// Si tiene transparencia (PNG), la rellena de blanco antes de convertir a JPG.
$width = imagesx($image);
$height = imagesy($image);

// Lado mayor máximo 1600 px: se ve nítida en pantallas grandes y pesa una fracción.
$maxSide = 1600;
$scale = min(1, $maxSide / max($width, $height));
$newW = max(1, (int)round($width * $scale));
$newH = max(1, (int)round($height * $scale));

$flat = imagecreatetruecolor($newW, $newH);
$white = imagecolorallocate($flat, 255, 255, 255);
imagefill($flat, 0, 0, $white);
imagecopyresampled($flat, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
imagedestroy($image);

// Un poco de nitidez para compensar la reducción.
if ($scale < 1 && function_exists('imageconvolution')) {
    @imageconvolution($flat, [[0, -0.4, 0], [-0.4, 2.6, -0.4], [0, -0.4, 0]], 1, 0);
}

$galleryDir = __DIR__ . '/../assets/gallery/';
if (!is_dir($galleryDir)) {
    mkdir($galleryDir, 0755, true);
}

// JPG progresivo (compatible con todo) + WebP (más ligero, lo usan los navegadores modernos).
imageinterlace($flat, true);
$destPath = $galleryDir . "foto-{$slot}.jpg";
$saved = imagejpeg($flat, $destPath, 80);
$webpOk = function_exists('imagewebp') && imagewebp($flat, $galleryDir . "foto-{$slot}.webp", 78);
// Solo se conserva el WebP si de verdad pesa menos que el JPG.
if (!$webpOk || (is_file($destPath) && filesize($galleryDir . "foto-{$slot}.webp") >= filesize($destPath))) { @unlink($galleryDir . "foto-{$slot}.webp"); }
imagedestroy($flat);

if (!$saved) {
    fail('No se pudo guardar la imagen. Revisa permisos de la carpeta assets/gallery/.', 500);
}

$kb = (int)round(filesize($destPath) / 1024);
dicosa_audit('galeria_foto', "Cambió la foto {$slot} ({$newW}x{$newH}, {$kb} KB)");
echo json_encode([
    'success' => true,
    'url' => "../assets/gallery/foto-{$slot}.jpg?t=" . time(),
    'kb' => $kb,
]);
