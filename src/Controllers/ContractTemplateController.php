<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Contracts\PdfRender;
use Align\Contracts\PdfStamp;
use Align\Contracts\Contracts;
use Align\Contracts\Render;
use Align\Contracts\Template;
use Align\DB;
use Align\Settings;
use Align\View;

/** Onboarding → Contract templates (2.2): the builder, its preview with sample values, import/export, contract settings. Admins. */
final class ContractTemplateController
{
    /** A template export, with its PDF (up to 25 MB) inside as base64. */
    private const MAX_IMPORT = 35 * 1024 * 1024;

    private static function load(int $id): array
    {
        $t = Template::load($id);
        if (!$t) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That contract template doesn\'t exist.']);
            exit;
        }
        return $t;
    }

    public static function index(): void
    {
        Auth::requireRole('admin');
        View::render('contracts/templates', [
            'title' => 'Contract templates',
            'nav' => 'contract-templates',
            'templates' => Template::all(),
            'companyAddress' => (string) Settings::get('company_address', ''),
            'remindDays' => Settings::int('contract_remind_days', 3),
            'maxReminders' => Settings::int('contract_max_reminders', 2),
        ]);
    }

    /** A new template: from the MSP's own PDF (the boxes are placed next), or written in Align. Nothing comes pre-filled. */
    public static function create(): void
    {
        Auth::requireRole('admin');
        if (post('start') === 'pdf') {
            try {
                $pdf = PdfStamp::storeSource($_FILES['file'] ?? []);
            } catch (\InvalidArgumentException $e) {
                flash('error', $e->getMessage());
                redirect('/contracts/templates');
            } catch (\RuntimeException $e) {
                self::saveFailed($e, '/contracts/templates');
            }
            $name = mb_substr(trim(post('name')), 0, 190) ?: (preg_replace('/\.pdf$/i', '', $pdf['name']) ?: 'New contract');
            $id = Template::create($name, Template::forPdf($pdf));
            Audit::log('contract_template.created', $name . ' (' . $pdf['name'] . ', ' . count($pdf['pages']) . ' pages)');
            flash('success', 'Uploaded ' . count($pdf['pages']) . ' page' . (count($pdf['pages']) === 1 ? '' : 's') . '. Now put the boxes on it: what you fill in, what the client fills in, and where everyone signs.');
            foreach ($pdf['notes'] as $note) {
                flash('info', $note);
            }
            redirect('/contracts/templates/' . $id);
        }
        $name = mb_substr(trim(post('name')), 0, 190) ?: 'New contract';
        $id = Template::create($name, Template::blank());
        Audit::log('contract_template.created', $name);
        redirect('/contracts/templates/' . $id);
    }

    /** A new version of the template's PDF. The boxes stay where they were (check them); contracts already made keep theirs. */
    public static function replacePdf(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        if (empty($t['def']['pdf'])) {
            redirect('/contracts/templates/' . $id);
        }
        try {
            $pdf = PdfStamp::storeSource($_FILES['file'] ?? []);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/contracts/templates/' . $id);
        } catch (\RuntimeException $e) {
            self::saveFailed($e, '/contracts/templates/' . $id);
        }
        $old = $t['def']['pdf'];
        $def = $t['def'];
        $def['pdf'] = $pdf;
        $kept = Template::normalize($def);
        Template::save($id, $t['name'], $t['description'], $kept, (bool) $t['is_active']);
        PdfStamp::removeIfUnused($old['file']);
        $lost = count($t['def']['places']) - count($kept['places']);
        Audit::log('contract_template.pdf', $t['name'] . ': ' . $pdf['name']);
        flash('success', 'New PDF uploaded (' . count($pdf['pages']) . ' page' . (count($pdf['pages']) === 1 ? '' : 's') . '). Check that the boxes are still in the right places.');
        foreach ($pdf['notes'] as $note) {
            flash('info', $note);
        }
        if ($lost > 0) {
            flash('warning', $lost . ' box' . ($lost === 1 ? ' was' : 'es were') . ' on pages the new PDF doesn\'t have, so ' . ($lost === 1 ? 'it was' : 'they were') . ' removed.');
        }
        redirect('/contracts/templates/' . $id);
    }

    /** The PDF couldn't be kept on the server (disk full, permissions): logged, and a plain message. */
    private static function saveFailed(\RuntimeException $e, string $back): never
    {
        error_log('[msp-align] contract template PDF not saved: ' . $e->getMessage());
        flash('error', 'The PDF couldn\'t be saved on the server. Ask your administrator to check the disk space and the uploads folder.');
        redirect($back);
    }

    /** The template's PDF, for the page viewer in the builder. */
    public static function source(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        Audit::access('contract_template.source', $t['name']);
        PdfStamp::serve($t['def']['pdf'] ?? null);
    }

    public static function show(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        $isPdf = !empty($t['def']['pdf']);
        View::render($isPdf ? 'contracts/template_pdf' : 'contracts/template', [
            'title' => $t['name'] . ' · Contract template',
            'nav' => 'contract-templates',
            't' => $t,
            'uses' => (int) DB::value('SELECT COUNT(*) FROM contracts WHERE template_id = ?', [$id]),
            'editor' => !$isPdf,
            'contractsJs' => true,
            'unknown' => Template::unknownKeys($t['def']),
        ]);
    }

    /**
     * The def posted by the builder (JSON in a hidden field). The PDF always comes from the saved template: it only
     * changes through "Upload a new version", so a builder open in another tab can't point back at an old file.
     */
    private static function postedDef(array $t): array
    {
        $d = json_decode((string) ($_POST['def'] ?? ''), true);
        if (!is_array($d)) {
            return empty($t['def']['pdf']) ? Template::blank() : $t['def'];
        }
        $d['pdf'] = $t['def']['pdf'] ?? null;
        return Template::normalize($d, true);
    }

    public static function save(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        try {
            $def = self::postedDef($t);
            Template::save($id, post('name'), post('description'), $def, isset($_POST['is_active']));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage() . ' Your changes weren\'t saved.');
            redirect('/contracts/templates/' . $id);
        }
        Audit::log('contract_template.saved', (string) DB::value('SELECT name FROM contract_templates WHERE id = ?', [$id]));
        $unknown = Template::unknownKeys($def);
        flash('success', 'Template saved.');
        if ($unknown) {
            flash('warning', 'These placeholders aren\'t fields yet, so they\'ll print empty: {{' . implode('}}, {{', $unknown) . '}}. Add them under Fields or fix the spelling.');
        }
        redirect('/contracts/templates/' . $id);
    }

    /** Live preview: the posted (unsaved) def with sample values, as HTML. */
    public static function preview(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        header('Content-Type: text/html; charset=utf-8');
        try {
            echo Render::html(Render::sample(self::postedDef($t)), 'preview');
        } catch (\InvalidArgumentException $e) {
            echo '<div class="alert alert-warning m-2">' . e($e->getMessage()) . '</div>';
        }
    }

    /** A sample PDF of the saved template. */
    public static function pdf(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        $pdf = PdfRender::build(Render::sample($t['def']), false);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; ' . content_filename($t['name'] . ' (sample).pdf'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    public static function duplicate(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        $new = Template::create(mb_substr('Copy of ' . $t['name'], 0, 190), $t['def'], $t['description']);
        Audit::log('contract_template.created', 'Copy of ' . $t['name']);
        flash('success', 'Copied. You\'re editing the copy.');
        redirect('/contracts/templates/' . $new);
    }

    public static function delete(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        if ((int) DB::value('SELECT COUNT(*) FROM contracts WHERE template_id = ?', [$id])) {
            DB::run('UPDATE contract_templates SET is_active = 0 WHERE id = ?', [$id]);
            Audit::log('contract_template.hidden', $t['name']);
            flash('success', 'Template hidden. It\'s kept because contracts were made from it; it no longer shows when you make a new contract.');
        } else {
            DB::run('DELETE FROM contract_templates WHERE id = ?', [$id]);
            if (!empty($t['def']['pdf'])) {
                PdfStamp::removeIfUnused($t['def']['pdf']['file']);
            }
            Audit::log('contract_template.deleted', $t['name']);
            flash('success', 'Template deleted.');
        }
        redirect('/contracts/templates');
    }

    public static function export(int $id): void
    {
        Auth::requireRole('admin');
        $t = self::load($id);
        Audit::log('contract_template.exported', $t['name'] . (empty($t['def']['pdf']) ? '' : ' (with its PDF)'));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . (preg_replace('/[^\w-]+/', '-', strtolower($t['name'])) ?: 'contract') . '.contract-template.json"');
        echo json_encode(Template::export($t), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function import(): void
    {
        Auth::requireRole('admin');
        $f = $_FILES['file'] ?? null;
        try {
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || filesize($f['tmp_name']) > self::MAX_IMPORT) {
                throw new \InvalidArgumentException('Choose a contract template file (.json, up to 35 MB).');
            }
            $data = json_decode((string) file_get_contents($f['tmp_name']), true);
            $id = Template::import(is_array($data) ? $data : []);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/contracts/templates');
        } catch (\RuntimeException $e) {
            self::saveFailed($e, '/contracts/templates');
        }
        Audit::log('contract_template.imported', (string) DB::value('SELECT name FROM contract_templates WHERE id = ?', [$id]));
        flash('success', 'Template imported.');
        redirect('/contracts/templates/' . $id);
    }

    public static function settings(): void
    {
        Auth::requireRole('admin');
        Settings::set('company_address', mb_substr(trim(str_replace("\r", '', post('company_address'))), 0, 500));
        Settings::set('contract_remind_days', (string) max(0, min(30, (int) post('contract_remind_days'))));
        Settings::set('contract_max_reminders', (string) max(0, min(10, (int) post('contract_max_reminders'))));
        Audit::log('settings.contracts', 'options');
        flash('success', 'Contract settings saved.');
        redirect('/contracts/templates');
    }
}
