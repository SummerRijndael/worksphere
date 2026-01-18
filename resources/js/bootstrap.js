import axios from 'axios';
window.axios = axios;

// Set base URL from environment variable for decoupled hosting
window.axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL || '/';

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
