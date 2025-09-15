{{-- Интеграция календаря с формой BidResource --}}

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    let currentSelectedElement = null;
    
    // Обработчик клика на события календаря с переключателем
    document.addEventListener('click', function(e) {
        const appointmentElement = e.target.closest('.active-appointment');
        if (appointmentElement) {
            // Убираем класс у предыдущего элемента
            if (currentSelectedElement) {
                currentSelectedElement.classList.remove('fc-event-selected');
            }
            
            // Добавляем класс к новому элементу
            appointmentElement.classList.add('fc-event-selected');
            currentSelectedElement = appointmentElement;
        }
    });

    
    // Добавляем стили для выделения выбранного времени
    const style = document.createElement('style');
    style.textContent = `
        
        .fc-event.fc-event-selected {
            background-color: #f54040 !important;
            border-color: #e83131 !important;
        }
        .fc-event.fc-event-selected::after {
            background: #f54040 !important;
            border-color: #e83131 !important;
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
