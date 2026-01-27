<?php

namespace Modules\Users\Services;
use Modules\Users\Models\Company\Clients;
class ClientsService extends AbstractService
{
    public function initialize()
    {
        $this->modelClass = Clients::class;
    }




    public function archive(int $id): bool
    {
        $client = Clients::findFirstOrFail($id);
        $client->archived = true;
        return $client->save();
    }

    public function changeStatus(int $id, string $status): bool
    {
        $client = Clients::findFirstOrFail($id);
        $client->status = $status;
        return $client->save();
    }

    public function assignManager(int $clientId, int $managerId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->manager_id = $managerId;
        return $client->save();
    }

    public function linkToRequest(int $clientId, int $requestId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->request_id = $requestId;
        return $client->save();
    }

    public function addNote(int $clientId, string $note): void
    {
        // Записати нотатку до клієнта
    }

    public function markAsVip(int $clientId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->is_vip = true;
        return $client->save();
    }

    public function ban(int $clientId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->status = 'banned';
        return $client->save();
    }

    public function unban(int $clientId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->status = 'active';
        return $client->save();
    }

    public function logActivity(int $clientId, string $activity): void
    {
        // Записати дію клієнта в лог
    }

    public function setPreferences(int $clientId, array $prefs): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->preferences = json_encode($prefs);
        return $client->save();
    }

    public function assignTag(int $clientId, string $tag): bool
    {
        // Додати тег до клієнта
        return true;
    }

    public function recordFeedback(int $clientId, string $feedback): void
    {
        // Зберегти відгук клієнта
    }

    public function setCommunicationChannel(int $clientId, string $channel): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->preferred_channel = $channel;
        return $client->save();
    }

    public function markAsProspect(int $clientId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->is_prospect = true;
        return $client->save();
    }

    public function convertToBuyer(int $clientId): bool
    {
        $client = Clients::findFirstOrFail($clientId);
        $client->type = 'buyer';
        return $client->save();
    }
}