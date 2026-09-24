<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Category;
use App\Services\CategoryService;

final class CategoryController extends Controller
{
    public function index(): void
    {
        $this->render('categories/index', ['categories' => (new Category())->all()], 'Categories');
    }

    public function store(): void
    {
        try {
            (new CategoryService())->create($this->input('name'), $this->input('color'), $this->input('sort_order'));
        } catch (\DomainException $e) {
            $this->failBack('categories', [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Category created.');
        redirect('categories');
    }

    public function edit(): void
    {
        $category = (new Category())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('categories/edit', ['category' => $category], 'Category: ' . $category['name']);
    }

    public function update(): void
    {
        $id = $this->inputInt('id');
        try {
            (new CategoryService())->update($id, $this->input('name'), $this->input('color'), $this->input('sort_order'), isset($_POST['is_active']));
        } catch (\DomainException $e) {
            $this->failBack('categories/edit', ['id' => $id], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Category saved.');
        redirect('categories');
    }

    public function delete(): void
    {
        $id = $this->inputInt('id');
        try {
            (new CategoryService())->delete($id);
        } catch (\DomainException $e) {
            $this->failBack('categories/edit', ['id' => $id], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Category deleted.');
        redirect('categories');
    }
}
