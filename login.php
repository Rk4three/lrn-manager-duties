<?php // auth/login.php ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login • Duty Manager Checklist</title>
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/favicon.png">
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#f5f3ff',
                            100: '#ede9fe',
                            200: '#ddd6fe',
                            300: '#c4b5fd',
                            400: '#a78bfa',
                            500: '#8b5cf6',
                            600: '#7c3aed',
                            700: '#6d28d9',
                            800: '#5b21b6',
                            900: '#4c1d95',
                        }
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-20px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #030014;
            background-image:
                radial-gradient(at 0% 0%, hsla(253, 16%, 7%, 1) 0, transparent 50%),
                radial-gradient(at 50% 0%, hsla(225, 39%, 30%, 1) 0, transparent 50%),
                radial-gradient(at 100% 0%, hsla(339, 49%, 30%, 1) 0, transparent 50%);
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .glass-panel {
            background: rgba(17, 25, 40, 0.75);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Modal Transitions */
        .modal-overlay {
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            transform: scale(0.95);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal-content {
            transform: scale(1);
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden text-slate-200">

    <!-- Background Decoration -->
    <div class="fixed inset-0 pointer-events-none">
        <div
            class="absolute top-[-10%] left-[-10%] w-[500px] h-[500px] bg-brand-600/20 rounded-full blur-[100px] animate-float">
        </div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[500px] h-[500px] bg-indigo-600/20 rounded-full blur-[100px] animate-float"
            style="animation-delay: 2s"></div>
    </div>

    <!-- Login Card -->
    <div
        class="w-full max-w-5xl glass-panel rounded-3xl overflow-hidden flex flex-col md:flex-row shadow-2xl relative z-10 transition-all duration-500 hover:shadow-brand-500/10">

        <!-- Brand Section -->
        <div
            class="w-full md:w-5/12 bg-gradient-to-br from-brand-900 to-indigo-900 p-12 flex flex-col justify-center items-center relative overflow-hidden">
            <!-- Decorative Patterns -->
            <div class="absolute inset-0 bg-[url('assets/img/pattern.svg')] opacity-10"></div>
            <div class="absolute top-0 right-0 w-64 h-64 bg-white/5 rounded-full blur-3xl"></div>

            <div class="relative z-10 text-center space-y-8">
                <div
                    class="w-24 h-24 bg-white/10 backdrop-blur-md rounded-2xl flex items-center justify-center mx-auto shadow-xl ring-1 ring-white/20 animate-float">
                    <i class="fas fa-clipboard-check text-5xl text-white drop-shadow-lg"></i>
                </div>

                <div class="space-y-4">
                    <h1 class="text-4xl font-bold tracking-tight text-white">
                        La Rose Noire
                    </h1>
                    <div class="w-12 h-1 bg-brand-400 rounded-full mx-auto"></div>
                    <p class="text-indigo-200 text-lg font-light tracking-wide uppercase">
                        Executive Management
                    </p>
                    <img src="assets/img/footer.png" alt="La Rose Noire" class="h-16 mx-auto grayscale invert opacity-80">
                </div>
            </div>

            <div class="absolute bottom-6 text-xs text-indigo-300/50 font-medium">
                Authorized Personnel Only
            </div>
        </div>

        <!-- Form Section -->
        <div class="w-full md:w-7/12 p-12 bg-slate-900/50 backdrop-blur-xl">
            <div class="max-w-md mx-auto space-y-8">
                <div class="text-center space-y-2">
                    <h2 class="text-3xl font-bold text-white">Welcome Back</h2>
                    <p class="text-slate-400">Please sign in to your dashboard</p>
                </div>

                <form action="actions/login_action.php" method="POST" class="space-y-6">
                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-slate-300 ml-1">Username</label>
                        <div class="relative group">
                            <div
                                class="absolute left-4 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center text-slate-500 group-focus-within:text-brand-400 group-focus-within:bg-brand-500/20 transition-all">
                                <i class="fas fa-user text-xs"></i>
                            </div>
                            <input type="text" name="username" required
                                class="w-full bg-slate-950/50 border border-slate-700 rounded-xl pl-14 pr-4 py-4 text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-all"
                                placeholder="Enter Biometrics ID">
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-slate-300 ml-1">Password</label>
                        <div class="relative group">
                            <div
                                class="absolute left-4 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center text-slate-500 group-focus-within:text-brand-400 group-focus-within:bg-brand-500/20 transition-all">
                                <i class="fas fa-lock text-xs"></i>
                            </div>
                            <input type="password" name="password" required id="password"
                                class="w-full bg-slate-950/50 border border-slate-700 rounded-xl pl-14 pr-12 py-4 text-white placeholder-slate-600 focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500 transition-all"
                                placeholder="••••••••">
                            <button type="button" onclick="togglePassword()"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-brand-400 p-2 transition-colors">
                                <i class="fas fa-eye text-sm" id="password-toggle"></i>
                            </button>
                        </div>

                        <div class="flex justify-end">
                            <button type="button" id="forgot-link"
                                class="text-sm text-slate-400 hover:text-brand-400 transition-colors flex items-center gap-1.5 group">
                                <i class="fas fa-key text-xs group-hover:rotate-45 transition-transform"></i>
                                Forgot Password?
                            </button>
                        </div>
                    </div>

                    <button type="submit"
                        class="w-full bg-gradient-to-r from-brand-600 to-indigo-600 hover:from-brand-500 hover:to-indigo-500 text-white font-bold py-4 rounded-xl shadow-lg shadow-brand-600/20 hover:shadow-brand-600/40 transform hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
                        <span>Sign In</span>
                        <i class="fas fa-arrow-right text-sm"></i>
                    </button>
                </form>

                <div class="pt-8 border-t border-white/5 text-center">
                    <p class="text-xs text-slate-500 flex items-center justify-center gap-2">
                        <i class="fas fa-shield-alt text-emerald-500/80"></i>
                        Secure System Access
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="forgot-modal"
        class="modal-overlay fixed inset-0 z-50 items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="modal-content glass-panel rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl">
            <div class="text-center">
                <div class="w-16 h-16 bg-brand-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-key text-3xl text-brand-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Reset Password</h3>
                <p class="text-slate-400 text-sm mb-6">Please contact the IT System Administrator to reset your
                    credentials.</p>
                <button onclick="closeModal()"
                    class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                    Understood
                </button>
            </div>
        </div>
    </div>

    <div id="error-modal"
        class="modal-overlay fixed inset-0 z-50 items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="modal-content glass-panel rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-rose-500/30">
            <div class="text-center">
                <div class="w-16 h-16 bg-rose-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-exclamation-triangle text-3xl text-rose-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Login Failed</h3>
                <p class="text-slate-400 text-sm mb-6">Invalid username or password. Please try again.</p>
                <button onclick="closeErrorModal()"
                    class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                    Try Again
                </button>
            </div>
        </div>
    </div>

    <div id="not-authorized-modal"
        class="modal-overlay fixed inset-0 z-50 items-center justify-center bg-black/80 backdrop-blur-sm">
        <div
            class="modal-content glass-panel rounded-2xl p-8 max-w-sm w-full mx-4 shadow-2xl border border-amber-500/30">
            <div class="text-center">
                <div class="w-16 h-16 bg-amber-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6">
                    <i class="fas fa-lock text-3xl text-amber-400"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Access Denied</h3>
                <p class="text-slate-400 text-sm mb-6">You are not authorized to access this system. Contact your
                    supervisor.</p>
                <button onclick="closeNotAuthorizedModal()"
                    class="w-full py-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-sm font-medium transition-colors">
                    Okay
                </button>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const password = document.getElementById('password');
            const toggle = document.getElementById('password-toggle');
            if (password.type === 'password') {
                password.type = 'text';
                toggle.className = 'fas fa-eye-slash text-sm';
            } else {
                password.type = 'password';
                toggle.className = 'fas fa-eye text-sm';
            }
        }

        function openModal() {
            document.getElementById('forgot-modal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('forgot-modal').classList.remove('active');
        }

        function openErrorModal() {
            document.getElementById('error-modal').classList.add('active');
        }

        function closeErrorModal() {
            document.getElementById('error-modal').classList.remove('active');
            const url = new URL(window.location);
            url.searchParams.delete('error');
            window.history.replaceState({}, '', url);
        }

        function openNotAuthorizedModal() {
            document.getElementById('not-authorized-modal').classList.add('active');
        }

        function closeNotAuthorizedModal() {
            document.getElementById('not-authorized-modal').classList.remove('active');
            const url = new URL(window.location);
            url.searchParams.delete('error');
            window.history.replaceState({}, '', url);
        }

        document.getElementById('forgot-link').addEventListener('click', openModal);

        // Close on overlay click
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function (e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('active'));
            }
        });

        // Initialize from URL
        window.addEventListener('DOMContentLoaded', function () {
            const urlParams = new URLSearchParams(window.location.search);
            const error = urlParams.get('error');
            if (error === 'invalid_credentials') openErrorModal();
            else if (error === 'not_authorized') openNotAuthorizedModal();
        });
    </script>
</body>

</html>