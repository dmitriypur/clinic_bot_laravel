/**
 * Интеграция календаря с формой BidResource
 * 
 * Обрабатывает события выбора времени из календаря и обновляет поля формы
 */

document.addEventListener('DOMContentLoaded', function() {
    // Слушаем события от календаря
    window.addEventListener('slotSelected', function(event) {
        const slotData = event.detail;
        
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
        const appointmentDatetimeField = document.querySelector('[data-field-name="appointment_datetime"] input');
        if (appointmentDatetimeField && slotData.appointment_datetime) {
            const date = new Date(slotData.appointment_datetime);
            const formattedDate = formatDateForInput(date);
            appointmentDatetimeField.value = formattedDate;
            
            // Триггерим событие изменения
            appointmentDatetimeField.dispatchEvent(new Event('change', { bubbles: true }));
        }
        
        // Обновляем связанные поля
        updateSelectField('city_id', slotData.city_id);
        updateSelectField('clinic_id', slotData.clinic_id);
        updateSelectField('branch_id', slotData.branch_id);
        updateSelectField('cabinet_id', slotData.cabinet_id);
        updateSelectField('doctor_id', slotData.doctor_id);
    }
    
    /**
     * Обновляет поле Select
     */
    function updateSelectField(fieldName, value) {
        const selectField = document.querySelector(`[data-field-name="${fieldName}"] select`);
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
        // Используем Filament уведомления если доступны
        if (window.filament && window.filament.notifications) {
            window.filament.notifications.create({
                title: message,
                type: type
            });
        } else {
            // Fallback на обычный alert
            alert(message);
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
