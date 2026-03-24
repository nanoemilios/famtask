# Familien-Aufgaben-App - Specification

## Project Overview
- **Project Name**: Familien-Aufgaben-App (Family Task App)
- **Type**: Web Application (PHP + MySQL + JavaScript)
- **Core Functionality**: A family task management system where children complete homework tasks to earn time credits, use timers for leisure activities, and parents manage everything via password-protected admin area
- **Target Users**: Families with children and parents

## Database Schema

### Tables
1. **children** - Child profiles
   - id, name, avatar, created_at

2. **tasks** - Task definitions (created by parents)
   - id, title, description, minutes_reward, category, child_id, created_by

3. **task_completions** - Completed tasks with timestamps
   - id, task_id, child_id, completed_at, minutes_earned

4. **time_credits** - Time balance tracking
   - id, child_id, minutes_total, minutes_used, updated_at

5. **timers** - Active leisure timers
   - id, child_id, activity_name, started_at, paused_at, is_running

6. **timer_logs** - Timer usage history
   - id, child_id, activity_name, duration_minutes, started_at, ended_at

7. **scheduled_tasks** - Calendar scheduled tasks
   - id, task_id, scheduled_date, scheduled_time, repeat_type (none/daily/weekly/monthly), repeat_until, created_at

8. **admin_settings** - Admin password and settings
   - id, password_hash, created_at

## UI/UX Specification

### Color Palette
- Primary: `#6366F1` (Indigo)
- Secondary: `#8B5CF6` (Purple)
- Accent: `#F59E0B` (Amber)
- Success: `#10B981` (Emerald)
- Danger: `#EF4444` (Red)
- Background: `#F8FAFC` (Slate 50)
- Card Background: `#FFFFFF`
- Text Primary: `#1E293B` (Slate 800)
- Text Secondary: `#64748B` (Slate 500)

### Typography
- Font Family: 'Nunito', sans-serif (headings), 'Inter', sans-serif (body)
- Headings: 700 weight
- Body: 400/500 weight

### Layout Structure
1. **Header**: Fixed top bar with app title, child selector dropdown, global timer display
2. **Main Content**: Card-based layout for tasks and activities
3. **Sidebar**: Quick stats for selected child

### Responsive Breakpoints
- Mobile: < 768px
- Tablet: 768px - 1024px
- Desktop: > 1024px

## Page Structure

### 1. Installation (install.php)
- Database connection form
- Admin password setup
- Creates all tables and initial data

### 2. Login (login.php)
- Simple password check for admin access
- Session-based authentication

### 3. Main Dashboard (index.php)
- Child selection (tabs or dropdown)
- Today's tasks list
- Quick action buttons
- Active timer display (global, visible on all pages)
- Current time credit balance

### 4. Task Management (admin.php)
- Add/Edit/Delete tasks
- Assign tasks to children
- Set minute rewards
- Category management

### 5. Calendar (calendar.php)
- Monthly view calendar
- Schedule tasks by date/time
- Repeat options: none, daily, weekly, monthly
- Visual indicators for scheduled tasks

### 6. Statistics (stats.php)
- Total minutes earned per child
- Minutes used vs remaining
- Task completion history
- Charts/graphs for visualization

### 7. Timer Interface (timer.php)
- Start/Pause/Stop timer for leisure
- Activity name input
- Visible on all pages via include

## Functionality Specification

### Timer System (Global)
- Timer state stored in database
- Real-time updates via JavaScript setInterval
- Visible in header on all pages
- Shows: child name, activity, elapsed time

### Task Completion Flow
1. Child views available tasks
2. Clicks "Erledigen" (Complete)
3. Task marked complete, minutes added to credit
4. Visual feedback (confetti/animation)

### Admin Features
- Password-protected area
- CRUD for children profiles
- CRUD for tasks
- Schedule tasks with repeat
- View all statistics

## Acceptance Criteria

1. ✓ Installation creates all database tables
2. ✓ Admin login works with password protection
3. ✓ Children can view and complete tasks
4. ✓ Time credits are tracked correctly
5. ✓ Timer runs and displays on all pages
6. ✓ Calendar shows scheduled tasks
7. ✓ Repeat scheduling works (daily/weekly/monthly)
8. ✓ Statistics show earned vs used minutes
9. ✓ Responsive design works on all devices
10. ✓ All UI text in German
