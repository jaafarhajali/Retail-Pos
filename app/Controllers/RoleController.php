<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;

final class RoleController extends Controller
{
    public function index(): void
    {
        $this->render('roles/index', ['roles' => (new Role())->all()], 'Roles & permissions');
    }

    public function store(): void
    {
        try {
            $id = (new RoleService())->create($this->input('name'));
        } catch (\DomainException $e) {
            $this->failBack('roles', [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Role created. Now choose its permissions.');
        redirect('roles/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $role = (new Role())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $permissions = new Permission();
        $groups = [];
        foreach ($permissions->all() as $permission) {
            $groups[$permission['group_name']][] = $permission;
        }
        $this->render('roles/edit', [
            'role'    => $role,
            'groups'  => $groups,
            'granted' => array_flip($permissions->forRole((int) $role['id'])),
        ], 'Role: ' . $role['name']);
    }

    public function permissions(): void
    {
        $id = $this->inputInt('id');
        try {
            (new RoleService())->setPermissions($id, (array) ($_POST['perms'] ?? []));
        } catch (\DomainException $e) {
            $this->failBack('roles/edit', ['id' => $id], ['perms' => $e->getMessage()]);
        }
        Flash::set('success', 'Permissions saved.');
        redirect('roles/edit', ['id' => $id]);
    }

    public function delete(): void
    {
        $id = $this->inputInt('id');
        try {
            (new RoleService())->delete($id);
        } catch (\DomainException $e) {
            $this->failBack('roles/edit', ['id' => $id], ['role' => $e->getMessage()]);
        }
        Flash::set('success', 'Role deleted.');
        redirect('roles');
    }
}
