<?php
require_once 'config/database.php';

echo "<h1>🚀 Despliegue de Foros [Fase 2] en Producción</h1>";
echo "<pre>";

try {
    // 1. Crear Tablas Maestras de Foros
    echo "[1] Construyendo Tablas Principales de Foros...\n";
    $sqlMaster = "
    CREATE TABLE IF NOT EXISTS `Forum` (
      `id` varchar(255) NOT NULL,
      `companyId` varchar(255) DEFAULT NULL,
      `businessUnitId` varchar(255) DEFAULT NULL,
      `targetRole` varchar(50) NOT NULL,
      `title` varchar(255) NOT NULL,
      `description` text,
      `isActive` tinyint(1) DEFAULT '1',
      `createdAt` datetime NOT NULL,
      `updatedAt` datetime NOT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS `ForumTopic` (
      `id` varchar(255) NOT NULL,
      `forumId` varchar(255) NOT NULL,
      `authorId` varchar(255) NOT NULL,
      `title` varchar(255) NOT NULL,
      `content` text NOT NULL,
      `views` int(11) DEFAULT '0',
      `threadType` varchar(50) DEFAULT 'GENERAL',
      `isPinned` tinyint(1) DEFAULT '0',
      `isLocked` tinyint(1) DEFAULT '0',
      `isValidatedPractice` tinyint(1) DEFAULT '0',
      `likesCount` int(11) DEFAULT '0',
      `createdAt` datetime NOT NULL,
      `updatedAt` datetime NOT NULL,
      PRIMARY KEY (`id`),
      CONSTRAINT `fk_topic_forum` FOREIGN KEY (`forumId`) REFERENCES `Forum` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS `ForumReply` (
      `id` varchar(255) NOT NULL,
      `topicId` varchar(255) NOT NULL,
      `authorId` varchar(255) NOT NULL,
      `parentReplyId` varchar(255) DEFAULT NULL,
      `content` text NOT NULL,
      `isHelpful` tinyint(1) DEFAULT '0',
      `helpfulVotesCount` int(11) DEFAULT '0',
      `likesCount` int(11) DEFAULT '0',
      `createdAt` datetime NOT NULL,
      PRIMARY KEY (`id`),
      CONSTRAINT `fk_reply_topic` FOREIGN KEY (`topicId`) REFERENCES `ForumTopic` (`id`) ON DELETE CASCADE,
      CONSTRAINT `fk_reply_parent` FOREIGN KEY (`parentReplyId`) REFERENCES `ForumReply` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlMaster);
    echo "  >> Tablas Forum, ForumTopic y ForumReply creadas (o ya existían).\n\n";

    // 2. Crear Tablas Relacionales (Interacciones y Votos)
    echo "[2] Construyendo Tablas de Votos y Likes...\n";
    $sqlLikes = "
    CREATE TABLE IF NOT EXISTS `ForumTopicLike` (
      `id` varchar(255) NOT NULL,
      `topicId` varchar(255) NOT NULL,
      `userId` varchar(255) NOT NULL,
      `createdAt` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_topic_user` (`topicId`, `userId`),
      CONSTRAINT `fk_topic_like` FOREIGN KEY (`topicId`) REFERENCES `ForumTopic` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS `ForumReplyLike` (
      `id` varchar(255) NOT NULL,
      `replyId` varchar(255) NOT NULL,
      `userId` varchar(255) NOT NULL,
      `createdAt` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_reply_user` (`replyId`, `userId`),
      CONSTRAINT `fk_reply_like` FOREIGN KEY (`replyId`) REFERENCES `ForumReply` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE IF NOT EXISTS `ForumReplyHelpfulVote` (
        `id` varchar(255) NOT NULL,
        `replyId` varchar(255) NOT NULL,
        `userId` varchar(255) NOT NULL,
        `createdAt` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_helpful_vote` (`replyId`, `userId`),
        CONSTRAINT `fk_vote_reply` FOREIGN KEY (`replyId`) REFERENCES `ForumReply` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_vote_user` FOREIGN KEY (`userId`) REFERENCES `User` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sqlLikes);
    echo "  >> Tablas ForumTopicLike, ForumReplyLike y ForumReplyHelpfulVote creadas.\n\n";

    // 3. Inyección de Reglas de Gamificación ("Logros y Medallas")
    echo "[3] Inyectando reglas base de Gamificación (XP) y Medallas...\n";
    if (!function_exists('genCuid')) {
        function genCuid() { return 'c'.uniqid().bin2hex(random_bytes(2)); }
    }

    $rules = [
        ['HELPFUL_ANSWER', 25, 'Respuesta útil validada por un Líder o la Comunidad'],
        ['VALIDATED_PRACTICE', 100, 'Aporte metodológico validado como Buena Práctica Oficial']
    ];

    foreach($rules as $r) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM GamificationRule WHERE actionType = ?");
        $chk->execute([$r[0]]);
        if($chk->fetchColumn() == 0) {
            $ins = $pdo->prepare("INSERT INTO GamificationRule (id, actionType, points, isActive, createdAt) VALUES (?, ?, ?, 1, NOW())");
            $ins->execute([genCuid(), $r[0], $r[1]]);
            echo "  >> Regla {$r[0]} inyectada con {$r[1]} puntos.\n";
        }
    }

    $badges = [
        [
            'title' => 'Voz de Sabiduría', 
            'desc' => 'Has aportado 5 respuestas coronadas como útiles a la comunidad.',
            'icon' => 'bx bxs-check-shield',
            'target' => 'HELPFUL_ANSWER',
            'thresh' => 5,
            'bonus' => 50,
            'color' => '#10b981'
        ],
        [
            'title' => 'Referente Operativo', 
            'desc' => 'Has documentado una Mejora o Práctica validada por la Dirección.',
            'icon' => 'bx bxs-medal',
            'target' => 'VALIDATED_PRACTICE',
            'thresh' => 1,
            'bonus' => 200,
            'color' => '#f59e0b'
        ]
    ];

    foreach ($badges as $b) {
        $checkBadge = $pdo->prepare("SELECT COUNT(*) FROM Achievement WHERE targetAction = ?");
        $checkBadge->execute([$b['target']]);
        if ($checkBadge->fetchColumn() == 0) {
            $insertBadge = $pdo->prepare("INSERT INTO Achievement (id, title, description, icon, targetAction, threshold, pointsBonus, color, isActive, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
            $insertBadge->execute([genCuid(), $b['title'], $b['desc'], $b['icon'], $b['target'], $b['thresh'], $b['bonus'], $b['color']]);
            echo "  >> Medalla Platino '{$b['title']}' inyectada.\n";
        }
    }

    echo "\n<br><h2 style='color: green;'>✅ TODA LA MIGRACIÓN A PRODUCCIÓN SE APLICÓ EXITOSAMENTE</h2>";

} catch(PDOException $e) {
    echo "\n<h2 style='color: red;'>❌ ERROR FATAL EN BASE DE DATOS: " . $e->getMessage() . "</h2>";
}

echo "</pre>";
