{{-- Интеграция календаря с формой BidResource --}}

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Слушаем события от календаря Livewire
    Livewire.on('slotSelected', function(slotData) {
        console.log('Получены данные слота:', slotData);
        
        // Обновляем поля формы
        updateFormFields(slotData);
        
        // Показываем уведомление
        showNotification('Время выбрано из календаря', 'success');
    });
    
    /**
     * Обновляет поля формы данными из календаря
     */
    function updateFormFields(slotData) {
        // Обновляем поле даты и времени
        const appointmentDatetimeField = document.querySelector('input[name="data.appointment_datetime"]');
        if (appointmentDatetimeField && slotData.appointment_datetime) {
            const date = new Date(slotData.appointment_datetime);
            const formattedDate = formatDateForInput(date);
            appointmentDatetimeField.value = formattedDate;
            
            // Триггерим событие изменения
            appointmentDatetimeField.dispatchEvent(new Event('change', { bubbles: true }));
            appointmentDatetimeField.dispatchEvent(new Event('input', { bubbles: true }));
        }
        
        // Обновляем связанные поля
        updateSelectField('data.city_id', slotData.city_id);
        updateSelectField('data.clinic_id', slotData.clinic_id);
        updateSelectField('data.branch_id', slotData.branch_id);
        updateSelectField('data.cabinet_id', slotData.cabinet_id);
        updateSelectField('data.doctor_id', slotData.doctor_id);
    }
    
    /**
     * Обновляет поле Select
     */
    function updateSelectField(fieldName, value) {
        const selectField = document.querySelector(`select[name="${fieldName}"]`);
        if (selectField && value) {
            selectField.value = value;
            selectField.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    
    /**
     * Форматирует дату для поля ввода
     */
    function formatDateForInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }
    
    /**
     * Показывает уведомление
     */
    function showNotification(message, type = 'info') {
        // Используем Filament уведомления
        if (window.$wire && window.$wire.$dispatch) {
            window.$wire.$dispatch('notify', {
                message: message,
                type: type
            });
        } else {
            console.log(message);
        }
    }
    
    // Добавляем стили для выделения выбранного времени
    const style = document.createElement('style');
    style.textContent = `
        .calendar-slot-selected {
            background-color: #3b82f6 !important;
            color: white !important;
            border: 2px solid #1d4ed8 !important;
        }
        
        .calendar-slot-selected:hover {
            background-color: #2563eb !important;
        }
        
        .fc-event.fc-event-selected {
            background-color: #3b82f6 !important;
            border-color: #1d4ed8 !important;
        }
    `;
    document.head.appendChild(style);
});

// Экспортируем функции для использования в других модулях
window.BidCalendarIntegration = {
    updateFormFields: function(slotData) {
        updateFormFields(slotData);
    },
    
    showNotification: function(message, type) {
        showNotification(message, type);
    }
};
</script>
@endpush
