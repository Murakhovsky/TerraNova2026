<?php
namespace Modules\Users\Services;

abstract class AbstractService
{
    protected string $modelClass;

    public function create(array $data)
    {
        $model = new $this->modelClass;
        $model->assign($data);
        if (!$model->save()) {
            throw new \Exception("Create failed");
        }
        return $model;
    }

    public function update(int $id, array $data)
    {
        $model = $this->find($id);
        $model->assign($data);
        if (!$model->save()) {
            throw new \Exception("Update failed");
        }
        return $model;
    }

    public function delete(int $id): bool
    {
        $model = $this->find($id);
        return $model->delete();
    }

    public function find(int $id)
    {
        $model = $this->modelClass::findFirst($id);
        if (!$model) {
            throw new \Exception("Not found");
        }
        return $model;
    }
}
