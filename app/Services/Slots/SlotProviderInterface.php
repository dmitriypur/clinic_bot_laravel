<?php

namespace App\Services\Slots;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface SlotProviderInterface
{
    /**
     * Возвращает коллекцию слотов в заданном диапазоне.
     *
     * @param  CarbonInterface  $from  Начало диапазона (UTC).
     * @param  CarbonInterface  $to  Конец диапазона (UTC).
     * @param  array  $filters  Дополнительные фильтры (doctor_ids, branch_ids и т.д.).
     * @param  User  $user  Пользователь, для которого подготавливаются слоты (учёт прав доступа).
     * @return Collection<int, SlotData>
     */
    public function getSlots(CarbonInterface $from, CarbonInterface $to, array $filters, User $user): Collection;
}
