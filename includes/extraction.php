<?php
/**
 * NexRaiz — Extraction du texte des documents déposés (PDF, DOCX) pour les
 * pré-remplir automatiquement dans l'éditeur de correction (Pack Classic),
 * au lieu de devoir les recopier à la main.
 *
 * Limites connues : les fichiers .doc (format binaire ancien) et les
 * images (CV scanné en PNG/JPG) ne sont pas pris en charge — il n'y a pas
 * d'OCR ici, seulement de l'extraction de texte déjà numérique.
 */
require_once __DIR__ . '/../vendor/autoload.php';

/**
 * @return string|null Le texte extrait, ou null si le format n'est pas
 * pris en charge ou si l'extraction a échoué.
 */
function extraire_texte_document(string $cheminAbsolu, string $extension): ?string
{
    if (!is_file($cheminAbsolu)) {
        return null;
    }

    $extension = strtolower($extension);
    if ($extension === 'pdf') {
        return extraire_texte_pdf($cheminAbsolu);
    }
    if ($extension === 'docx') {
        return extraire_texte_docx($cheminAbsolu);
    }
    return null;
}

function extraire_texte_pdf(string $cheminAbsolu): ?string
{
    try {
        $parser = new \Smalot\PdfParser\Parser();
        $document = $parser->parseFile($cheminAbsolu);
        $texte = trim($document->getText());
        return $texte !== '' ? $texte : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function extraire_texte_docx(string $cheminAbsolu): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($cheminAbsolu) !== true) {
        return null;
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return null;
    }

    // Approche volontairement simple (sans résolution de namespace XML,
    // plus robuste face aux variations de structure Word) : on transforme
    // les marqueurs de paragraphe/saut de ligne en retours à la ligne
    // avant de retirer le reste des balises.
    $xml = preg_replace('/<w:p\b[^>]*>/', "\n", $xml);
    $xml = preg_replace('/<w:tab\s*\/>/', "\t", $xml);
    $xml = preg_replace('/<w:br\s*\/>/', "\n", $xml);
    $texte = strip_tags((string) $xml);
    $texte = html_entity_decode($texte, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $texte = preg_replace('/\n{3,}/', "\n\n", $texte);
    $texte = trim((string) $texte);

    return $texte !== '' ? $texte : null;
}
