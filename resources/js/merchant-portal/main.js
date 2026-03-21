import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from '../router';
import { useAuthStore } from '../stores/auth';
import App from './App.vue';
import '../../css/app.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);

const authStore = useAuthStore();
await authStore.bootstrapAuth();

app.use(router);
app.mount('#app');
