import './bootstrap';
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import '../css/app.css';
import './bootstrap';
import './calendar-notifications';

// Версия фронтенда; обновляем строку при каждом релизе, чтобы принудительно сбрасывать старый кэш WebApp
const APP_CACHE_VERSION = '2024-05-26'
const CACHE_STORAGE_KEY = 'clinic_bot_app_version'

const ensureFreshClientVersion = () => {
    if (typeof window === 'undefined' || typeof window.localStorage === 'undefined') {
        return
    }

    try {
        const storedVersion = window.localStorage.getItem(CACHE_STORAGE_KEY)

        if (storedVersion === APP_CACHE_VERSION) {
            return
        }

        window.localStorage.setItem(CACHE_STORAGE_KEY, APP_CACHE_VERSION)

        const url = new URL(window.location.href)
        if (url.searchParams.get('__v') !== APP_CACHE_VERSION) {
            url.searchParams.set('__v', APP_CACHE_VERSION)
            window.location.replace(url.toString())
            return
        }

        window.location.reload()
    } catch (error) {
        console.warn('Не удалось синхронизировать версию приложения', error)
    }
}

ensureFreshClientVersion()

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
})
