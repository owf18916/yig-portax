import { installAxios } from './composables/useSessionApi.js';
import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

installAxios(axios);
