// Обработчик уведомлений для календаря заявок
document.addEventListener('DOMContentLoaded', function() {
    // Слушаем кастомные события уведомлений от календаря
    window.addEventListener('notify', function(event) {
        const { type, title, body } = event.detail;
        
        // Создаем уведомление через Filament
        if (window.Livewire) {
            window.Livewire.dispatch('notify', {
                type: type,
                title: title,
                body: body
            });
        }
    });
    
    // Добавляем обработчик для установки времени в скрытое поле
    document.addEventListener('calendar-time-selected', function(event) {
        const { datetime } = event.detail;
        
        // Находим скрытое поле appointment_datetime
        const hiddenField = document.querySelector('input[name="data[appointment_datetime]"]');
        if (hiddenField) {
            hiddenField.value = datetime;
            hiddenField.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
});
