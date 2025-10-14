<template>
    <div class="text-center space-y-4">
        <h2 class="text-2xl font-bold text-gray-800">Онлайн-запись</h2>
        <BaseButton
            class="w-full py-3 font-medium"
            variant="primary"
            @click="$emit('next')"
        >
            Записаться на приём
        </BaseButton>
    </div>
</template>

<script setup>
import BaseButton from '../../../../Components/ui/BaseButton.vue'

defineEmits(['next'])
</script>
