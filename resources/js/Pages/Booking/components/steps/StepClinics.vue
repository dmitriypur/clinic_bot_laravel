<template>
    <div class="space-y-4">
        <h3 class="text-xl font-semibold text-gray-800">Выберите клинику</h3>

        <div v-if="isLoading" class="text-sm text-gray-500">
            Загружаем список клиник...
        </div>

        <div v-else-if="!clinics.length" class="text-sm text-gray-500">
            Клиники для выбранного города не найдены.
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="clinic in clinics"
                :key="clinic.id"
                class="rounded-lg border border-gray-200 bg-gray-50"
            >
                <button
                    type="button"
                    @click="$emit('toggle-clinic', clinic)"
                    :class="[
                        'w-full flex justify-between items-center px-4 py-3 text-left rounded-lg transition',
                        selectedClinicId === clinic.id ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-indigo-50'
                    ]"
                >
                    <span class="font-medium text-gray-800">{{ clinic.name }}</span>
                    <span class="text-xs text-gray-500">
                        {{ expandedClinicId === clinic.id ? 'Скрыть' : 'Филиалы' }}
                    </span>
                </button>

                <div
                    v-if="expandedClinicId === clinic.id"
                    class="border-t border-gray-200 px-4 py-3 space-y-2 bg-white rounded-b-lg"
                >
                    <div v-if="loadingBranchesId === clinic.id" class="text-sm text-gray-500">
                        Загружаем филиалы...
                    </div>
                    <template v-else>
                        <template v-if="(branchesByClinic[clinic.id]?.length ?? 0) > 1">
                            <p class="text-sm text-gray-600">Выберите филиал:</p>
                            <div class="space-y-2">
                                <BaseButton
                                    v-for="branch in branchesByClinic[clinic.id]"
                                    :key="branch.id"
                                    class="w-full justify-start px-3 py-2 text-left"
                                    :variant="selectedBranchId === branch.id ? 'primary' : 'outline'"
                                    @click.stop="$emit('select-branch', { clinicId: clinic.id, branch })"
                                >
                                    <span class="font-medium text-gray-800">{{ branch.name }}</span>
                                    <span v-if="branch.address" class="block text-xs text-gray-500 mt-1 ml-3">
                                        {{ branch.address }}
                                    </span>
                                </BaseButton>
                            </div>
                        </template>
                        <template v-else>
                            <p class="text-sm text-gray-600">
                                Филиал выбран автоматически.
                            </p>
                        </template>
                    </template>
                </div>
            </div>
        </div>

        <BaseButton variant="ghost" class="text-sm" @click="$emit('back')">
            ← Назад
        </BaseButton>
    </div>
</template>

<script setup>
import BaseButton from '../../../../Components/ui/BaseButton.vue'

defineProps({
    clinics: {
        type: Array,
        default: () => [],
    },
    branchesByClinic: {
        type: Object,
        default: () => ({}),
    },
    expandedClinicId: {
        type: [Number, String, null],
        default: null,
    },
    selectedClinicId: {
        type: [Number, String, null],
        default: null,
    },
    selectedBranchId: {
        type: [Number, String, null],
        default: null,
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    loadingBranchesId: {
        type: [Number, String, null],
        default: null,
    },
})

defineEmits(['toggle-clinic', 'select-branch', 'back'])
</script>
