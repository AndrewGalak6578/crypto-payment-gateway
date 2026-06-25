import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuthStore } from '../stores/auth';
import '../../css/app.css';
import './styles/tokens.css';
import './styles/app.css';
import './styles/payments.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);

const authStore = useAuthStore();
await authStore.bootstrapAuth();

app.use(router);
app.mount('#app');
