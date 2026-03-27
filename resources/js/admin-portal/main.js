import { createApp } from "vue";
import { createPinia } from "pinia";
import router from "../router/admin";
import { useAdminAuthStore } from "../stores/adminAuth";
import App from "./App.vue";
import '../../css/app.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);

const adminAuthStore = useAdminAuthStore();
await adminAuthStore.bootstrapAuth();

app.use(router);
app.mount("#admin-app");
