document.addEventListener('DOMContentLoaded', () => {
    // Lógica para alternar el menú lateral en dispositivos móviles
    const menuToggle = document.getElementById('mobile-menu-toggle');
    const sidebar = document.getElementById('sidebar');

    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });

        // Cerrar al hacer clic fuera
        document.addEventListener('click', (e) => {
            if (sidebar.classList.contains('open') && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Efectos simples u otras inicializaciones (Alertas auto escondibles, validación básica)
    console.log("HubEurosoft Classic Inicializado.");
});
