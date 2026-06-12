<?php

require 'config/database.php';

class CryptoHelper
{
    // Clave de 32 bytes para AES-256. 
    // ¡IMPORTANTE!: Debe ser exactamente la misma en el archivo VB.NET.
    private static $key = "h5PMsghjHy3hqHmkcfOrF3XtTzYgKI2f";

    /**
     * Cifra una cadena y devuelve un token seguro para URL.
     */
    public static function encrypt($plainText)
    {
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($ivLength); // Generar un IV dinámico y aleatorio

        // Cifrar usando la clave y el IV generado
        $encrypted = openssl_encrypt($plainText, 'aes-256-cbc', self::$key, OPENSSL_RAW_DATA, $iv);

        // Concatenar el IV (16 bytes) + el Texto Cifrado
        $result = $iv . $encrypted;

        // Convertir a Base64 y hacerlo seguro para parámetros URL (URL-Safe)
        $base64 = base64_encode($result);
        $urlSafe = str_replace(['+', '/', '='], ['-', '_', ''], $base64);

        return $urlSafe;
    }

    /**
     * Recibe un token de una URL y lo descifra a su texto original.
     */
    public static function decrypt($cipherTextUrlSafe)
    {
        // Restaurar el Base64 URL-Safe a un Base64 estándar
        $base64 = str_replace(['-', '_'], ['+', '/'], $cipherTextUrlSafe);
        $pad = strlen($base64) % 4;
        if ($pad) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        $fullCipher = base64_decode($base64);
        if ($fullCipher === false || strlen($fullCipher) < 16) {
            return false; // Error en la decodificación o datos corruptos
        }

        $ivLength = openssl_cipher_iv_length('aes-256-cbc');

        // Extraer el IV (los primeros 16 bytes) y el mensaje cifrado (el resto)
        $iv = substr($fullCipher, 0, $ivLength);
        $cipherText = substr($fullCipher, $ivLength);

        // Descifrar y retornar
        return openssl_decrypt($cipherText, 'aes-256-cbc', self::$key, OPENSSL_RAW_DATA, $iv);
    }
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

// Definición de la de obtencion de id
function ObtenerRolEquivalenteHUB($rolCode) {
    
    //LISTA DE CODIGOS DE ROL QUE SE ENVIAN EN EL MENSAJE

    /*
    Code	ROLES HUB

    24	    LECTOR OPERATIVO
    22	    LECTOR ADMINISTRATIVO 
    21	    MODELADOR
    23	    INTEGRADOR
    20	    ADMINISTRADOR DOCUMENTAL
        
    40	    COORDINADOR DE AUDITORIAS
    41	    AUDITOR LIDER
    42	    AUDITOR INTERNO
        
    30	    MIEMBRO COMITÉ
    31	    ANALISTA DE RIESGOS
        
    OTRAS CARACTERISTICAS	
        
    100	    ADMINISTRADOR DE CONFIGURACION
    101	    APROBADOR DE DOCUMENTOS
    102	    ANALISTA DE CAUSA RAIZ
    103     RESPONSABLE DE PLAN DE ACCION (NO EXISTE EN HUB)
    104	    PARTICIPANTE DE ATENCION DE INCIDENCIAS
    105	    GESTOR DE APRENDIZAJE
    */

    switch ($rolCode) {
        case 22:
            $idRolHub = 'cmmpit1eq000bwnrbemtn6f94';
            break;
        case 24:
            $idRolHub = 'cmmpit70t000cwnrb0bcprd2m';
            break;
        case 21:
            $idRolHub = 'c69c609ed03e4b0092';
            break;
        case 23:
            $idRolHub = 'cmmpiqz7j0009wnrbp3eyobvv';
            break;
        case 20:
            $idRolHub = 'cmmpix0zs000iwnrbhtnmf2b8';
            break; 

        case 40:
            $idRolHub = 'cmmpitq7f000dwnrb78d5a486';
            break;
        case 41:
            $idRolHub = 'cmmpiu5mb000ewnrblkgg93nk';
            break;
        case 42:
            $idRolHub = 'cmmpiueby000fwnrb5ge6vrvp';
            break;

        case 30:
            $idRolHub = '';
            break;
        case 31:
            $idRolHub = '';
            break; 

        case 100:
            $idRolHub = 'cmmpipsno0007wnrbdkt7ggb4';
            break;
        case 101:
            $idRolHub = 'cmmpireh7000awnrbv29qu620';
            break;
        case 102:
            $idRolHub = 'cmmpiux4y000gwnrb8g2cb32a';
            break;
        case 103:
            $idRolHub = '';
            break;
        case 104:
            $idRolHub = 'cmmpiwl0m000hwnrbun8bqva2';
            break;                    
        case 105:
            $idRolHub = 'c69c5d73722b1ddde7';
            break;                    
    }
    return $idRolHub;
}

// RECIBIR de VB.NET a PHP:
if (isset($_GET['TOKEN'])) {
    $tokenRecibido = $_GET['TOKEN'];
    $textoOriginal = CryptoHelper::decrypt($tokenRecibido);
    echo "Datos TOKEN: " . $textoOriginal;

    // Asignamos la cadena original contenida en TOKEN al segundo parámetro ($resultado) que es un arreglo donde se guardarán los valores

    parse_str($textoOriginal, $datos);

    $OPE = $datos['OPE'];

    switch ($OPE) {

        // ACCIONES CON COMPAÑIA

        case 200: // ALTA COMPAÑIA

            // OPE=200&CID=230426154425&CNM=NOMBRE EMPRESA&ISA=1

            try {
                $cuid = generateCuid();
                $idgse = $datos['CID'];
                $name = $datos['CNM'];
                $isActive = $datos['ISA'];
                $logoPath = "";

                $stmt = $pdo->prepare("INSERT INTO Company (id, IDGSE, name, isActive, logoPath, updatedAt) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$cuid, $idgse, $name, $isActive, $logoPath]);
                $successMsg = "Cliente creado exitosamente.";

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break; 
        case 201: // ACTUALIZACION COMPAÑIA
            try {
                $id = $datos['CID'];
                $name = $datos['CNM'];
                $isActive = $datos['ISA'];

                $stmtCompany = $pdo->prepare("SELECT id FROM Company WHERE IDGSE = ?");
                $stmtCompany->execute([$id]);
                $companyId = $stmtCompany->fetchColumn();

                if ($companyId) {

                    $params = [$name, $isActive, $id];

                    $stmt = $pdo->prepare("UPDATE Company SET name = ?, isActive = ?, updatedAt = NOW() WHERE IDGSE = ?");
                    $stmt->execute($params);
                    $successMsg = "Cliente actualizado.";

                } else {

                    try {
                        $cuid = generateCuid();
                        $idgse = $datos['CID'];
                        $name = $datos['CNM'];
                        $isActive = $datos['ISA'];
                        $logoPath = "";

                        $stmt = $pdo->prepare("INSERT INTO Company (id, IDGSE, name, isActive, logoPath, updatedAt) VALUES (?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$cuid, $idgse, $name, $isActive, $logoPath]);
                        $successMsg = "Cliente creado exitosamente.";

                    } catch (PDOException $e) {
                        $successMsg = "" . $e->getMessage();
                    }
                }

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;
        case 202: // ELIMINAR COMPAÑIA
            try {
                $idgse = $datos['CID'];

                $stmtCompany = $pdo->prepare("SELECT id FROM Company WHERE IDGSE = ?");
                $stmtCompany->execute([$idgse]);

                // 2. Fetch the first column from the first row
                $companyId = $stmtCompany->fetchColumn();

                $pdo->prepare("DELETE FROM User WHERE companyId = ?")->execute([$companyId]);
                $pdo->prepare("DELETE FROM BusinessUnit WHERE companyId = ?")->execute([$companyId]);
                $stmt = $pdo->prepare("DELETE FROM Company WHERE id = ?");
                $stmt->execute([$companyId]);
                $successMsg = "El Cliente, sus Unidades de Negocio y sus Usuarios registrados han sido erradicados del sistema de forma permanente.";

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;

        // ACCIONES CON UNIDAD DE NEGOCIO

        case 300: // ALTA UNIDAD DE NEGOCIO
            
            // OPE=300&CID=230426165752&UNID=230426165932&CNM=PRO FRONT END&ISA=1
            
            try {
                $idgse = $datos['CID'];

                $stmtCompany = $pdo->prepare("SELECT id FROM Company WHERE IDGSE = ?");
                $stmtCompany->execute([$idgse]);
                $companyId = $stmtCompany->fetchColumn();

                $cuid = generateCuid();
                $unid = $datos['UNID'];
                $name = $datos['CNM'];
                $isActive = $datos['ISA'];
                $logoPath = "";

                $stmt = $pdo->prepare("INSERT INTO BusinessUnit (id, IDGSE, name, companyId, isActive, logoPath, updatedAt) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$cuid, $unid, $name, $companyId, $isActive, $logoPath]);
                $successMsg = "Unidad creada.";

            } catch (PDOException $e) {
                $successMsg = "". $e->getMessage();
            }
            break;
        case 301: // ACTUALIZACION UNIDAD DE NEGOCIO

            // OPE={0}&CID={1}&UNID={2}&CNM={3}&ISA={4}
            
            try {
                $idgse = $datos['CID'];
                $unid = $datos['UNID'];
                $name = $datos['CNM'];
                $isActive = $datos['ISA'];

                $stmtCompany = $pdo->prepare("SELECT id FROM Company WHERE IDGSE = ?");
                $stmtCompany->execute([$idgse]);
                $companyId = $stmtCompany->fetchColumn();

                if ($companyId) {

                    $stmtUN = $pdo->prepare("SELECT id FROM BusinessUnit WHERE companyId = ? AND IDGSE = ?");
                    $stmtUN->execute([$companyId, $unid]);
                    $unId = $stmtUN->fetchColumn(); 
                    
                    if ($unId) {
                        $params = [$name, $isActive, $unid, $companyId];

                        $stmt = $pdo->prepare("UPDATE BusinessUnit SET name = ?, isActive = ?, updatedAt = NOW() WHERE IDGSE = ? AND companyId = ?");
                        $stmt->execute($params);
                        $successMsg = "Unidad actualizada.";
                    } else {
                        $cuid = generateCuid();
                        $logoPath = "";

                        $stmt = $pdo->prepare("INSERT INTO BusinessUnit (id, IDGSE, name, companyId, isActive, logoPath, updatedAt) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $stmt->execute([$cuid, $unid, $name, $companyId, $isActive, $logoPath]);
                        $successMsg = "Unidad creada.";                        
                    }
                }

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;
        case 302: // ELIMINAR UNIDAD DE NEGOCIO

            // OPE={0}&CID={1}&UNID={2}
            // OPE=302&CID=230426165752&UNID=230426172816

            try {
                $idgse = $datos['CID'];
                $unid = $datos['UNID'];

                $stmtCompany = $pdo->prepare("SELECT id FROM Company WHERE IDGSE = ?");
                $stmtCompany->execute([$idgse]);
                $companyId = $stmtCompany->fetchColumn();

                $stmtUN = $pdo->prepare("SELECT id FROM BusinessUnit WHERE companyId = ? AND IDGSE = ?");
                $stmtUN->execute([$companyId, $unid]);
                $unId = $stmtUN->fetchColumn();

                $pdo->prepare("DELETE FROM User WHERE businessUnitId = ?")->execute([$unId]);
                $pdo->prepare("DELETE FROM BusinessUnit WHERE id = ?")->execute([$unId]);
                $successMsg = "Unidad eliminada.";

                // CONSIDERAR ELIMINAR LOS ELEMENTOS ASOCIADOS A LA UNIDAD DE NEGOCIO TAL COMO USUARIOS,  etc

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;

        // ACCIONES CON USUARIOS        

        case 1: // ALTA USUARIO

            // OPE=1&CID=01589623&UID=18&UFN=Zoe Armando&ULN=Lopez Gonzalez&EML=pumpi@hotmail.com&NNM=agonzalez&PSW=Sysmngr02#

            try {
                $cid = $datos['CID'];
                $uid = $datos['UID'];
                $ufn = trim($datos['UFN'] ?? '');
                $uln = trim($datos['ULN'] ?? '');
                $email = trim($datos['EML'] ?? '');
                $nnm = trim($datos['NNM'] ?? '');
                $psw = trim($datos['PSW'] ?? '');
                
                $name = trim("$ufn $uln");
                $hash = password_hash($psw, PASSWORD_BCRYPT);
                $newId = generateCuid();
                
                if (empty($email)) {
                    $email = "{$newId}@hubeurosoft.com";
                }
                $role = "STUDENT";
 
                $stmtUN = $pdo->prepare("SELECT id, companyId FROM BusinessUnit WHERE IDGSE = ?");
                $stmtUN->execute([$cid]);
                $row = $stmtUN->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $unid = $row['id'];
                    $companyId = $row['companyId'];

                    $stmt = $pdo->prepare("INSERT INTO User (id, IDSuite, name, firstName, lastName, nickname, email, passwordHash, role, companyId, businessUnitId, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$newId, $uid, $name, $ufn, $uln, $nnm, $email, $hash, $role, $companyId, $unid]);

                    $successMsg = "Usuario creado.";
                }

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;

        case 2: // ACTUALIZACION USUARIO
           
            // OPE=2&CID=01589623&UID=18&UFN=Luis Armando&ULN=Lopez Gonzalez&EML=pumpi@hotmail.com&NNM=agonzalez&PSW=Sysmngr02#

            try {
                $cid = $datos['CID'];
                $uid = $datos['UID'];
                $ufn = trim($datos['UFN'] ?? '');
                $uln = trim($datos['ULN'] ?? '');
                $email = trim($datos['EML'] ?? '');
                $nnm = trim($datos['NNM'] ?? '');
                $psw = trim($datos['PSW'] ?? '');
                
                if (empty($email)) {
                    $email = "{$uid}@hubeurosoft.com";
                }
                $name = trim("$ufn $uln");
                $hash = password_hash($psw, PASSWORD_BCRYPT);

                $stmtUN = $pdo->prepare("SELECT id, companyId FROM BusinessUnit WHERE IDGSE = ?");
                $stmtUN->execute([$cid]);
                $row = $stmtUN->fetch(PDO::FETCH_ASSOC);
                
                if ($row) {
                    $unid = $row['id'];
                    $companyId = $row['companyId'];

                    $stmtU = $pdo->prepare("SELECT id FROM User WHERE (companyId = ?) AND (businessUnitId = ?) AND (IDSuite = ?)");
                    $stmtU->execute([$companyId, $unid, $uid]);
                    $id = $stmtU->fetchColumn();

                    if ($id) {
                        $stmt = $pdo->prepare("UPDATE User SET firstName=?, lastName=?, name=?, nickname=?, email=?, passwordHash=?, updatedAt=NOW() 
                                               WHERE companyId = ? AND businessUnitId = ? AND IDSuite = ?");
                        $stmt->execute([$ufn, $uln, $name, $nnm, $email, $hash, $companyId, $unid, $uid]);
                        $successMsg = "Usuario actualizado.";
                    } else {
                        $newId = generateCuid();
                        $role = "STUDENT";
                        $stmt = $pdo->prepare("INSERT INTO User (id, IDSuite, name, firstName, lastName, nickname, email, passwordHash, role, companyId, businessUnitId, createdAt, updatedAt) 
                                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                        $stmt->execute([$newId, $uid, $name, $ufn, $uln, $nnm, $email, $hash, $role, $companyId, $unid]);
                        $successMsg = "Usuario creado.";
                    }
                }
            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;

        case 3: // ELIMINACION DE USUARIO

            // OPE={0}&CID={1}&UID={2}

            // CONSIDERAR ELIMINAR LO RELACIONADO A LA CUENTA DE USUARIO TAL COMO ASIGNACIONES A CURSOS, GRUPOS, ETC

            try {
                $cid = $datos['CID'];
                $uid = $datos['UID'];

                $stmtUN = $pdo->prepare("SELECT id, companyId FROM BusinessUnit WHERE IDGSE = ?");
                $stmtUN->execute([$cid]);
                $rowUN = $stmtUN->fetch(PDO::FETCH_ASSOC);

                echo "<br><br>recupero datos de la UN";

                if ($rowUN) {
                    $unid = $rowUN['id'];
                    $companyId = $rowUN['companyId'];

                    $stmtU = $pdo->prepare("SELECT id FROM User WHERE (companyId = ?) AND (businessUnitId = ?) AND (IDSuite = ?)");
                    $stmtU->execute([$companyId, $unid, $uid]);
                    $id = $stmtU->fetchColumn();

                    try {
                        $pdo->prepare("DELETE FROM _TrainingRoleToUser WHERE B = ?")->execute([$id]);
                    } catch (Exception $e) {
                    }
                    $stmt = $pdo->prepare("DELETE FROM User WHERE id = ?");
                    $stmt->execute([$id]);
                    $successMsg = "Usuario eliminado.";
                }

            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;

        case 4: // ASIGNAR UN ROL

            // OPE={0}&CID={1}&UID={2}&ROL={3}
            // OPE=4&CID=01589623&UID=22&ROL=22&CLS=1

            try {
                $cid = $datos['CID'];
                $uid = $datos['UID'];
                $rol = $datos['ROL'];

                $idRolHub = ObtenerRolEquivalenteHUB($rol);

                $stmtUN = $pdo->prepare("SELECT id, companyId FROM BusinessUnit WHERE IDGSE = ?");
                $stmtUN->execute([$cid]);
                $rowUN = $stmtUN->fetch(PDO::FETCH_ASSOC);

                if ($rowUN) {
                    $unid = $rowUN['id'];
                    $companyId = $rowUN['companyId'];

                    $stmtU = $pdo->prepare("SELECT id FROM User WHERE (companyId = ?) AND (businessUnitId = ?) AND (IDSuite = ?)");
                    $stmtU->execute([$companyId, $unid, $uid]);
                    //$rowUser = $stmtU->fetch(PDO::FETCH_ASSOC);
                    $id = $stmtU->fetchColumn();
            
                    if ($id) {
                        //$id = $rowUser['id'];

                        $stmtTR = $pdo->prepare("INSERT INTO _TrainingRoleToUser (A, B) VALUES (?, ?)");
                        $stmtTR->execute([$idRolHub, $id]);

                        $successMsg = "Rol asignado.";
                    }
                }
            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;
        case 5: // QUITAR ROL

            // OPE={0}&CID={1}&UID={2}&ROL={3}

            try {
                $cid = $datos['CID'];
                $uid = $datos['UID'];
                $rol = $datos['ROL'];

                $idRolHub = ObtenerRolEquivalenteHUB($rol);

                $stmtUN = $pdo->prepare("SELECT id, companyId FROM BusinessUnit WHERE IDGSE = ?");
                $stmtUN->execute([$cid]);
                $rowUN = $stmtUN->fetch(PDO::FETCH_ASSOC);

                if ($rowUN) {
                    $unid = $rowUN['id'];
                    $companyId = $rowUN['companyId'];

                    $stmtU = $pdo->prepare("SELECT id FROM User WHERE (companyId = ?) AND (businessUnitId = ?) AND (IDSuite = ?)");
                    $stmtU->execute([$companyId, $unid, $uid]);
                    $id = $stmtU->fetchColumn();

                    if ($id) {
                        $pdo->prepare("DELETE FROM CourseProgress WHERE userId = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM TopicProgress WHERE userId = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM LessonProgress WHERE userId = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM StudentAnswer WHERE userId = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM _TrainingRoleToUser WHERE A = ? AND B = ?")->execute([$idRolHub, $id]);

                        $successMsg = "Rol desasignado.";
                    }
                }
            } catch (PDOException $e) {
                $successMsg = "" . $e->getMessage();
            }
            break;
        
    }

 echo $successMsg;
}


?>