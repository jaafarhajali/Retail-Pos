<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Models\Setting;
use App\Services\SettingService;

final class SettingController extends Controller
{
    public function index(): void
    {
        $this->render('settings/index', ['values' => (new Setting())->all()], 'Settings');
    }

    public function logo(): void
    {
        $file = $_FILES['logo'] ?? null;
        try {
            if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] === UPLOAD_ERR_NO_FILE) {
                throw new \DomainException('Choose an image file for the logo.');
            }
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new \DomainException('The logo must be 5 MB or smaller.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new \DomainException('The upload failed. Try again.');
            }
            (new SettingService())->saveLogo((string) $file['tmp_name'], (int) $file['size'], (string) ($file['name'] ?? ''));
        } catch (\DomainException $e) {
            $this->failBack('settings', [], ['logo' => $e->getMessage()]);
        }
        Flash::set('success', 'Logo saved. It shows on the menu, the sign-in page and receipts.');
        redirect('settings');
    }

    public function logoDelete(): void
    {
        (new SettingService())->removeLogo();
        Flash::set('success', 'Logo removed.');
        redirect('settings');
    }

    public function save(): void
    {
        try {
            (new SettingService())->save($_POST);
        } catch (\DomainException $e) {
            $this->failBack('settings', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Settings saved.');
        redirect('settings');
    }
}
