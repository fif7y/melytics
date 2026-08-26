import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import './style.css'
import App from './App.vue'
import Login from './views/Login.vue'
import Dashboard from './views/Dashboard.vue'
import Share from './views/Share.vue'
import { token } from './lib/api'

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/login', component: Login },
    { path: '/share/:token', component: Share },
    { path: '/:siteId?', component: Dashboard },
  ],
})

router.beforeEach((to) => {
  if (to.path !== '/login' && !to.path.startsWith('/share/') && !token()) return '/login'
})

createApp(App).use(router).mount('#app')
