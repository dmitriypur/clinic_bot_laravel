<script setup>
import { computed, onMounted } from 'vue'

import BookingProgress from './Booking/components/BookingProgress.vue'
import StepSelectCity from './Booking/components/steps/StepSelectCity.vue'
import StepBirthDate from './Booking/components/steps/StepBirthDate.vue'
import StepChooseMode from './Booking/components/steps/StepChooseMode.vue'
import StepClinics from './Booking/components/steps/StepClinics.vue'
import StepDoctors from './Booking/components/steps/StepDoctors.vue'
import StepSchedule from './Booking/components/steps/StepSchedule.vue'
import StepConfirmation from './Booking/components/steps/StepConfirmation.vue'
import { useBooking } from './Booking/composables/useBooking'

const {
    state,
    datepickerLocale,
    hasAvailableSlots,
    selectedDateLabel,
    appTimezone,
    currentStepPosition,
    totalSteps,
    isPromoFlow,
    actions,
} = useBooking()

onMounted(() => {
    actions.initTelegramContext()
    actions.loadCities()
})

// Находим выбранную клинику для отображения сводной информации
const selectedClinic = computed(() => state.clinics.find((clinic) => clinic.id === state.clinicId) ?? null)

// Находим выбранный филиал исходя из текущей клиники
const selectedBranch = computed(() => {
    if (!state.clinicId) {
        return null
    }
    const branches = state.branchesByClinic[state.clinicId] || []
    return branches.find((branch) => branch.id === state.branchId) ?? null
})

// Имя клиники берём из выбранного слота при его наличии, иначе — из локального выбора
const clinicName = computed(() => {
    if (state.selectedSlot?.clinic_name) {
        return state.selectedSlot.clinic_name
    }
    return selectedClinic.value ? selectedClinic.value.name : ''
})

// Аналогично определяем имя филиала
const branchName = computed(() => {
    if (state.selectedSlot?.branch_name) {
        return state.selectedSlot.branch_name
    }
    return selectedBranch.value ? selectedBranch.value.name : ''
})
</script>

<template>
    <div class="min-h-screen flex items-center justify-center">
        <div class="w-full min-h-screen max-w-md bg-white px-6 py-12 space-y-6">

            <!-- Прогресс строится относительно активного сценария (обычный или по промокоду) -->
            <BookingProgress :current="currentStepPosition" :total="totalSteps" />

            <!-- Первый шаг сразу показывает форму для заполнения персональных данных -->
            <StepConfirmation
                v-if="state.step === 1"
                v-model:fio="state.fio"
                v-model:child-fio="state.childFio"
                v-model:phone="state.phone"
                v-model:consent="state.consent"
                v-model:promo-code="state.promoCode"
                :selected-slot="null"
                :doctor="null"
                :clinic-name="''"
                :branch-name="''"
                :selected-date-label="selectedDateLabel"
                :tg-user-id="state.tgUserId"
                :tg-chat-id="state.tgChatId"
                :show-selection-details="false"
                :show-back-button="false"
                :show-form="true"
                submit-label="Далее"
                @submit="actions.handleInitialFormSubmit"
            />

            <!-- Далее следуют стандартные шаги мастера -->
            <StepSelectCity
                v-else-if="state.step === 2"
                :cities="state.cities"
                :is-loading="state.isLoadingCities"
                @select="actions.selectCity"
                @back="actions.goBack"
            />

            <StepBirthDate
                v-else-if="state.step === 3"
                v-model="state.birthDate"
                @next="actions.goTo(4)"
                @skip="() => { actions.setBirthDate(null); actions.goTo(4) }"
                @back="actions.goBack"
                :datepicker-locale="datepickerLocale"
            />

            <StepChooseMode
                v-else-if="state.step === 4"
                @choose-clinic="actions.startClinicFlow"
                @choose-doctor="actions.startDoctorFlow"
                @back="actions.goBack"
            />

            <StepClinics
                v-else-if="state.step === 5"
                :clinics="state.clinics"
                :branches-by-clinic="state.branchesByClinic"
                :expanded-clinic-id="state.expandedClinicId"
                :selected-clinic-id="state.clinicId"
                :selected-branch-id="state.branchId"
                :is-loading="state.isLoadingClinics"
                :loading-branches-id="state.loadingBranchesId"
                :is-promo-flow="isPromoFlow"
                @toggle-clinic="actions.toggleClinic"
                @select-branch="actions.selectBranch"
                @select-clinic="actions.selectClinicForPromo"
                @back="actions.goBack"
            />

            <StepDoctors
                v-else-if="state.step === 6"
                :doctors="state.doctors"
                :selected-doctor-id="state.selectedDoctorId"
                :is-loading="state.isLoadingDoctors"
                @select="actions.selectDoctor"
                @back="actions.goBack"
            />

            <StepSchedule
                v-else-if="state.step === 7"
                v-model:selected-date="state.selectedDate"
                :min-date="state.minDate"
                :slots="state.slots"
                :selected-slot="state.selectedSlot"
                :is-loading-slots="state.isLoadingSlots"
                :has-available-slots="hasAvailableSlots"
                :selected-date-label="selectedDateLabel"
                :datepicker-locale="datepickerLocale"
                :timezone="appTimezone"
                :doctor="state.selectedDoctor"
                :clinic-name="clinicName"
                :branch-name="branchName"
                :slot-validation-error="state.slotValidationError"
                :is-validating-slot="state.isSlotValidationInProgress"
                @change-date="actions.onDateChange"
                @select-slot="actions.selectSlot"
                @next="actions.handleScheduleNext"
                @skip="actions.skipSchedule"
                @back="actions.goBack"
            />

            <StepConfirmation
                v-else-if="state.step === 8"
                :selected-slot="state.selectedSlot"
                :selected-date-label="selectedDateLabel"
                :doctor="state.selectedDoctor"
                :clinic-name="clinicName"
                :branch-name="branchName"
                v-model:fio="state.fio"
                v-model:child-fio="state.childFio"
                v-model:phone="state.phone"
                v-model:consent="state.consent"
                v-model:promo-code="state.promoCode"
                :tg-user-id="state.tgUserId"
                :tg-chat-id="state.tgChatId"
                :show-selection-details="true"
                :show-form="false"
                :submit-label="isPromoFlow ? 'Отправить' : 'Записаться'"
                @submit="actions.submit"
                @back="actions.goBack"
            />

        </div>
    </div>
</template>
