# Prompt para Stitch (Google) — Frontend Gestión de Usuarios

Basado en `docs/Developer_Technical_Test.md` y en las reglas de validación de `app/Models/UserModel.php`.
Prompt en inglés (Stitch rinde mejor); textos de UI en español para coincidir con los mensajes del backend.

```
Design a clean, modern web admin app called "Gestión de Usuarios" (user management CRUD) for desktop (1440px) with responsive tablet/mobile variants. Built later in React + TypeScript, so use a consistent, reusable component system (buttons, inputs, select, table, modal, toast, badge, skeleton, empty state). Style: minimal SaaS dashboard, light theme, neutral grays, one primary accent (indigo/blue), red for destructive actions, 8px spacing grid, rounded-lg corners, subtle shadows, Inter font. All UI copy in Spanish.

DATA MODEL (User):
- id (number, read-only)
- first_name "Nombre" — required, max 100
- last_name "Apellido" — required, max 100
- email "Email" — required, valid format, unique
- gender "Género" — required, select: Masculino / Femenino / Otro
- telephone "Teléfono" — optional, max 20, digits + ( ) - space only
- age "Edad" — required, integer 1–120

SCREENS:

1. Users list (main screen)
- Top bar: app title, primary button "+ Nuevo usuario".
- Data table with columns: ID, Nombre completo, Email, Género (small badge), Teléfono (show "—" if empty), Edad, Acciones (icon buttons: Editar pencil, Eliminar trash in red).
- Show ~10 realistic sample rows (Latin American names).
- Row hover state, zebra or subtle dividers, header sticky.
- Mobile: table collapses into stacked cards with same info and actions.

2. Users list — loading state
- Same layout with skeleton rows in the table, disabled "Nuevo usuario" button.

3. Users list — error state
- Inline error panel replacing the table: icon, "No se pudieron cargar los usuarios", secondary text "Verifica que la API esté disponible", button "Reintentar".

4. Users list — empty state
- Illustration/icon, "Aún no hay usuarios", CTA "Crear primer usuario".

5. Create user form (modal dialog or side drawer)
- Title "Nuevo usuario". Two-column grid on desktop: Nombre | Apellido, Email | Teléfono (marked "opcional"), Género select | Edad number input.
- Required fields marked with asterisk.
- Footer: "Cancelar" (secondary) and "Guardar" (primary).

6. Create form — validation errors state
- Same form showing client-side inline errors under fields in red with red borders: "El nombre es obligatorio", "El email no tiene un formato válido", "La edad debe ser un número entero entre 1 y 120", "El teléfono solo admite números, +, -, ( ) y espacios".
- Also a server-side error example on email: "El email ya está registrado." (from API 422).

7. Create form — submitting state
- "Guardar" button with spinner and "Guardando…", fields disabled.

8. Edit user form
- Same component as create, title "Editar usuario #12", pre-filled with data, button "Guardar cambios".
- Variant: "Usuario no encontrado" message inside the modal (API 404) with "Volver al listado".

9. Delete confirmation dialog
- Small modal with warning icon: "¿Eliminar usuario?", body "Se eliminará a María González (maria.gonzalez@example.com). Esta acción no se puede deshacer.", buttons "Cancelar" and red "Eliminar" (also show its loading variant "Eliminando…").

10. Feedback toasts
- Success toasts (top-right): "Usuario creado", "Usuario actualizado", "Usuario eliminado".
- Error toast: "Ocurrió un error. Intenta de nuevo."

Keep all screens visually consistent so they map to reusable components: UserTable, UserRow, UserForm (shared by create/edit), ConfirmDialog, Toast, LoadingSkeleton, ErrorState, EmptyState.
```

## Tips

- Si Stitch no genera bien todo en una pasada: enviar primero pantallas 1, 5 y 9; luego pedir los estados (2–4, 6–8, 10) como variantes "same design".
- Para UI en inglés: cambiar "All UI copy in Spanish" y los textos entre comillas.
