    </div> <!-- Cierre de App Container -->

    <!-- Scripts Base -->
    <script src="assets/js/main.js"></script>
    <script>
        // FIX GLOBAL: Mover los modales al final del body para escapar restricciones de CSS (overflow, transform, etc.)
        // Esto garantiza que el oscurecimiento abarque el 100% de la ventana en todas las vistas (clientes, cursos, etc)
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('.modal-overlay').forEach(modal => {
                document.body.appendChild(modal);
            });
        });
    </script>
</body>
</html>
