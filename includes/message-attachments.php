<?php
/**
 * Pièces jointes de messagerie (dossier client ou messages internes) :
 * upload, validation et affichage, partagés par toutes les pages de
 * messagerie du site pour un comportement identique partout.
 */

const MESSAGE_PIECE_JOINTE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'csv'];
const MESSAGE_PIECE_JOINTE_TAILLE_MAX = 10 * 1024 * 1024; // 10 Mo

/**
 * Valide et déplace la pièce jointe éventuellement envoyée dans $_FILES.
 * Retourne null si aucun fichier n'a été fourni. Lève une RuntimeException
 * si le fichier fourni est invalide (taille, extension).
 */
function traiter_piece_jointe_message(string $champ = 'piece_jointe'): ?array
{
    if (empty($_FILES[$champ]['name']) || $_FILES[$champ]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$champ]['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Erreur lors de l'envoi du fichier.");
    }
    if ($_FILES[$champ]['size'] > MESSAGE_PIECE_JOINTE_TAILLE_MAX) {
        throw new RuntimeException('Le fichier dépasse la taille maximale autorisée (10 Mo).');
    }
    $extension = strtolower(pathinfo($_FILES[$champ]['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, MESSAGE_PIECE_JOINTE_EXTENSIONS, true)) {
        throw new RuntimeException('Type de fichier non autorisé.');
    }

    $uploadsDir = __DIR__ . '/../uploads/messages';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }
    $nomFichier = bin2hex(random_bytes(8)) . '.' . $extension;
    $cheminAbsolu = $uploadsDir . '/' . $nomFichier;
    if (!move_uploaded_file($_FILES[$champ]['tmp_name'], $cheminAbsolu)) {
        throw new RuntimeException("Impossible d'enregistrer le fichier.");
    }

    return [
        'nom' => basename($_FILES[$champ]['name']),
        'chemin' => 'uploads/messages/' . $nomFichier,
        'taille' => (int) $_FILES[$champ]['size'],
    ];
}

function message_piece_jointe_icone(string $nomFichier): string
{
    $extension = strtolower(pathinfo($nomFichier, PATHINFO_EXTENSION));
    if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) return 'image';
    if (in_array($extension, ['xls', 'xlsx', 'csv'], true)) return 'table_chart';
    if (in_array($extension, ['ppt', 'pptx'], true)) return 'slideshow';
    return 'description';
}

function message_taille_lisible(?int $octets): string
{
    if (!$octets) return '';
    if ($octets < 1024) return $octets . ' o';
    if ($octets < 1024 * 1024) return round($octets / 1024) . ' Ko';
    return round($octets / (1024 * 1024), 1) . ' Mo';
}

/** Affiche la carte de pièce jointe dans une bulle de message, si présente. */
function render_message_attachment(?string $nom, ?string $chemin, ?int $taille, bool $estMoi): void
{
    if (!$chemin) {
        return;
    }
    ?>
    <a href="<?php echo htmlspecialchars($chemin); ?>" target="_blank" rel="noopener" class="flex items-center gap-space-xs rounded-lg p-space-xs mb-1 transition-colors <?php echo $estMoi ? 'bg-black/5 hover:bg-black/10' : 'bg-surface-container-low hover:bg-surface-container-high'; ?>">
      <span class="w-9 h-9 rounded-lg bg-white flex items-center justify-center shrink-0 text-primary shadow-sm"><span class="material-symbols-outlined text-[20px]"><?php echo message_piece_jointe_icone($nom ?? ''); ?></span></span>
      <span class="min-w-0 flex-1">
        <span class="block font-body-sm text-[13px] text-[#111b21] font-semibold truncate"><?php echo htmlspecialchars($nom ?? 'Document'); ?></span>
        <span class="block font-body-sm text-[11px] text-[#667781]"><?php echo htmlspecialchars(message_taille_lisible($taille)); ?></span>
      </span>
      <span class="material-symbols-outlined text-[18px] text-[#667781] shrink-0">download</span>
    </a>
    <?php
}
