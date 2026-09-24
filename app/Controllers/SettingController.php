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
