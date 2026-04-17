<?php
require_once 'config/database.php';

$code = $_GET['code'] ?? '';
$cert = null;

if ($code) {
    try {
        $stmt = $pdo->prepare("
            SELECT uc.issuedAt, u.name as fullName, c.name as certificateName, 
                   c.description as certificateDescription, c.imageUrl as certificateImage,
                   co.title as courseName
            FROM usercertificate uc
            JOIN User u ON uc.userId = u.id
            JOIN Certificate c ON uc.certificateId = c.id
            LEFT JOIN Course co ON uc.courseId = co.id
            WHERE uc.verificationCode = ?
        ");
        $stmt->execute([$code]);
        $cert = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error verificando certificado: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Certificado | HubEurosoft</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <!-- Tailwind CSS (via CDN) para replicar exactamente el diseño de React/NextJS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">

    <?php if (!$cert): ?>
        
        <div class="bg-white rounded-2xl shadow-xl p-12 max-w-md w-full text-center">
            <div class="text-5xl mb-4">❌</div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">Certificado no encontrado</h1>
            <p class="text-gray-500 mb-6 font-medium">
                El código de verificación <code class="bg-gray-100 text-gray-700 px-2 py-1 rounded text-sm font-mono tracking-widest"><?php echo htmlspecialchars($code ?: 'VACÍO'); ?></code> no corresponde a ningún certificado emitido y válido en nuestro sistema.
            </p>
            <a href="index.php" class="text-indigo-600 hover:text-indigo-800 hover:underline text-sm font-bold transition">
                Volver al inicio &rarr;
            </a>
        </div>

    <?php else: ?>

        <?php 
            $issuedDate = date('d \d\e F \d\e Y', strtotime($cert['issuedAt']));
            // Traducción simple de meses 
            $meses_en = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            $meses_es = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            $issuedDate = str_replace($meses_en, $meses_es, $issuedDate);
        ?>
        
        <div class="min-h-screen w-full bg-gradient-to-br from-indigo-50 via-white to-purple-50 flex items-center justify-center p-6">
            <div class="max-w-2xl w-full" style="animation: dropIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);">
                
                <!-- Verified badge -->
                <div class="flex justify-center mb-6">
                    <div class="bg-green-100/80 backdrop-blur-sm text-green-700 font-bold px-5 py-2 rounded-full text-sm flex items-center gap-2 shadow-sm border border-green-200">
                        <span class="text-lg">✅</span> Certificado Emitido y Auténtico
                    </div>
                </div>

                <!-- Certificate card -->
                <div class="bg-white rounded-3xl shadow-2xl overflow-hidden border border-gray-100 transform transition-transform hover:scale-[1.01] duration-300">
                    
                    <!-- Gold header -->
                    <div class="bg-gradient-to-r from-amber-400 via-yellow-400 to-yellow-500 px-8 py-8 text-white text-center shadow-inner relative overflow-hidden">
                        <!-- Abstract shapes -->
                        <div class="absolute top-0 left-0 w-32 h-32 bg-white opacity-10 rounded-full blur-2xl -translate-x-10 -translate-y-10"></div>
                        <div class="absolute bottom-0 right-0 w-32 h-32 bg-yellow-600 opacity-20 rounded-full blur-2xl translate-x-10 translate-y-10"></div>
                        
                        <p class="relative z-10 text-amber-900/60 text-xs font-bold uppercase tracking-[0.3em] mb-2">Hubeurosoft Corporativo</p>
                        <h1 class="relative z-10 text-4xl font-black tracking-tight" style="text-shadow: 0 2px 4px rgba(0,0,0,0.1);">Certificado de Formación</h1>
                    </div>

                    <!-- Body -->
                    <div class="px-12 py-12 text-center relative">
                        <!-- Watermark -->
                        <div class="absolute inset-0 flex items-center justify-center opacity-[0.02] pointer-events-none">
                            <svg class="w-64 h-64" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 22h20L12 2zm0 3.83L18.17 19H5.83L12 5.83z"/></svg>
                        </div>
                        
                        <p class="text-gray-400 text-sm font-semibold tracking-wider uppercase mb-3 relative z-10">Se certifica formalmente que</p>
                        <h2 class="text-5xl font-black text-gray-800 mb-6 relative z-10 tracking-tight text-transparent bg-clip-text bg-gradient-to-br from-gray-900 to-gray-600"><?php echo htmlspecialchars($cert['fullName']); ?></h2>

                        <?php if ($cert['courseName']): ?>
                            <p class="text-gray-500 font-medium mb-3 relative z-10 text-lg">
                                Ha completado satisfactoriamente la ruta de capacitación
                            </p>
                            <span class="inline-block relative z-10 font-bold text-indigo-700 text-xl bg-indigo-50/50 px-6 py-2 rounded-xl border border-indigo-100/50">
                                "<?php echo htmlspecialchars($cert['courseName']); ?>"
                            </span>
                        <?php endif; ?>

                        <div class="my-10 flex items-center justify-center relative z-10">
                            <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-200 to-transparent"></div>
                            <span class="mx-6 text-5xl filter drop-shadow-md">🏅</span>
                            <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-200 to-transparent"></div>
                        </div>

                        <div class="relative z-10 max-w-lg mx-auto">
                            <h3 class="text-2xl font-bold text-gray-800 mb-2"><?php echo htmlspecialchars($cert['certificateName']); ?></h3>
                            <?php if ($cert['certificateDescription']): ?>
                                <p class="text-gray-500 text-sm leading-relaxed"><?php echo htmlspecialchars($cert['certificateDescription']); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($cert['certificateImage']): ?>
                                <div class="mt-8 flex justify-center">
                                    <div class="bg-white p-3 rounded-2xl shadow-sm border border-gray-100">
                                        <img src="<?php echo htmlspecialchars($cert['certificateImage']); ?>" alt="Sello de Certificación" class="h-24 object-contain filter drop-shadow-sm">
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="bg-gray-50 border-t border-gray-100 px-10 py-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                        <div class="text-center sm:text-left">
                            <p class="font-bold text-xs uppercase tracking-wider text-gray-400 mb-1">Fecha de expedición</p>
                            <p class="font-semibold text-gray-700"><?php echo $issuedDate; ?></p>
                        </div>
                        <div class="text-center sm:text-right">
                            <p class="font-bold text-xs uppercase tracking-wider text-gray-400 mb-1">Id de Verificación Blockchain</p>
                            <code class="text-xs bg-white border border-gray-200 text-gray-600 px-3 py-1.5 rounded-lg font-mono tracking-widest shadow-sm select-all"><?php echo htmlspecialchars($code); ?></code>
                        </div>
                    </div>
                </div>

                <p class="text-center text-xs text-gray-400 font-medium mt-6 max-w-md mx-auto leading-relaxed">
                    Este certificado digital es rastreado por el Sistema Core de HubEurosoft y puede ser verificado permanentemente usando el protocolo y código listados.
                </p>
            </div>
        </div>

        <style>
            @keyframes dropIn {
                0% { opacity: 0; transform: translateY(-30px) scale(0.98); }
                100% { opacity: 1; transform: translateY(0) scale(1); }
            }
        </style>

    <?php endif; ?>

</body>
</html>
