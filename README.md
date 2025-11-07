# 🏋️‍♂️ Gym Coach

¡Hola! Soy **Alvaro**, y he creado **Gym Coach**, un programa que me facilita muchísimo avanzar en el gimnasio.  
Esta app **analiza mis pesos, repeticiones y progreso**, y automáticamente **me propone nuevos objetivos de carga y repeticiones** para seguir progresando sin estancarme 💪.

---


## ⚙️ Instalación y Configuración

### 🧩 1. Requisitos
Necesitas tener instalado:

- **MySQL o MariaDB**
- **PHP 7+**
- (Opcional) Un entorno tipo **XAMPP**, **Laragon**, o **InfinityFree**

---

### 🧠 2. Crear la base de datos
En el repositorio hay un archivo llamado **`base_de_datos.sql`**.  
📋 **Copia todo su contenido** y pégalo en tu **consola SQL o phpMyAdmin**, luego ejecútalo.

✅ Esto creará **la base de datos y todas las tablas necesarias** automáticamente.

---

### 🔑 3. Conectar tu base de datos
Abre el archivo **`index.php`** y busca las siguientes líneas al inicio:

```php
const DB_HOST = 'tu_host';
const DB_NAME = 'tu_base_de_datos';
const DB_USER = 'tu_usuario';
const DB_PASS = 'tu_contraseña';
```

## 🚀 ¿Qué hace este programa?

Gym Coach es una **app web en PHP + MySQL** (solo un archivo `index.php`) que:

- 📅 Te deja **crear rutinas** (PPL, Full Body, etc.).
- 📆 Cada rutina tiene **días** (Día 1, Día 2…).
- 🏋️‍♂️ En cada día puedes **añadir ejercicios** (Press banca, Sentadilla...).
- ✏️ Puedes **anotar tus sesiones**: peso, series, reps, RPE, observaciones...
- 📈 El sistema **analiza tu rendimiento** y ajusta automáticamente tu siguiente objetivo:
  - Si lo hiciste fácil → sube peso ✅  
  - Si te costó mucho → baja peso 💤  
  - Si te estancaste → añade series 🔁  

---

## ⚙️ Cómo funciona la **sobrecarga progresiva automática**

Gym Coach usa un sistema inteligente que analiza tus últimas 6 sesiones por ejercicio:

| Situación | Qué hace el sistema |
|------------|--------------------|
| Reps y RPE buenos | ➕ Sube peso (2.5 kg por paso) |
| Reps bien pero RPE alto | ➡️ Mantiene peso |
| Fatiga o menos reps | 🔽 Baja peso (deload) |
| 3+ sesiones sin mejora | ➕ Añade una serie más |
| Sin historial | Empieza desde 20 kg o tu último registro global |

### 🧠 Funciones clave del sistema de sobrecarga
- `compute_next_target()` → Calcula el siguiente peso, reps y series.  
- `update_next_target_for_day_exercise()` → Guarda el nuevo objetivo.  
- `get_last_logs_for_ex_in_day()` → Carga tus últimas sesiones.  
- `round_to_2_5() / inc_2_5() / dec_2_5()` → Mantienen pesos en múltiplos de 2.5 kg.  

---

## 💻 Cómo usar la web

1. **Regístrate** con usuario + PIN numérico.
2. Crea una **rutina** (por ejemplo, “Push Pull Legs”).
3. Dentro, crea tus **días** (“Pecho”, “Piernas”…).
4. Añade **ejercicios**.
5. Cada día puedes:
   - ✅ Marcar ejercicios completados.
   - 📝 Anotar tus sesiones (peso, reps, RPE…).
   - 📊 Ver el historial y editar/borrar registros.
   - 🎯 Ver tu siguiente objetivo calculado automáticamente.

---

## 🧩 Base de datos mínima (MySQL)

```sql
CREATE DATABASE gymcoach;
USE gymcoach;

-- Crea tus tablas (users, routines, routine_days, day_exercises, exercise_logs, exercise_checks)
-- Puedes copiarlas fácilmente desde el código PHP.
