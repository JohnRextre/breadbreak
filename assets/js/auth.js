document.addEventListener('DOMContentLoaded', function () {
    const toggleButtons = document.querySelectorAll('.toggle-password');

    toggleButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const targetId = this.dataset.target;
            const input = document.getElementById(targetId);

            if (!input) {
                return;
            }

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            
            const icon = this.querySelector('i');
            if (icon) {
                icon.className = isPassword ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
            }
            this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        });
    });

    const passwordField = document.getElementById('password');
    const strengthText = document.getElementById('strengthText');

    if (passwordField && strengthText) {
        const updateStrength = function () {
            const value = passwordField.value;
            let strength = 'Weak';

            if (value.length >= 8) {
                const hasUpper = /[A-Z]/.test(value);
                const hasLower = /[a-z]/.test(value);
                const hasNumber = /\d/.test(value);
                const hasSymbol = /[^A-Za-z0-9]/.test(value);

                const score = [hasUpper, hasLower, hasNumber, hasSymbol].filter(Boolean).length;

                strength = score >= 3 ? 'Strong' : 'Medium';
            }

            strengthText.textContent = strength;
            strengthText.style.color = strength === 'Strong' ? '#1f8f5d' : strength === 'Medium' ? '#d08a2d' : '#b5332c';
        };

        passwordField.addEventListener('input', updateStrength);
    }

    const forms = document.querySelectorAll('.auth-form');

    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const formType = form.dataset.formType;
            let isValid = true;

            const clearError = function (fieldName) {
                const errorElement = form.querySelector('[data-error-for="' + fieldName + '"]');
                const field = form.querySelector('[name="' + fieldName + '"]');

                if (errorElement) {
                    errorElement.textContent = '';
                }

                if (field) {
                    field.classList.remove('error-field');
                    field.setAttribute('aria-invalid', 'false');
                }
            };

            const setError = function (fieldName, message) {
                const errorElement = form.querySelector('[data-error-for="' + fieldName + '"]');
                const field = form.querySelector('[name="' + fieldName + '"]');

                if (errorElement) {
                    errorElement.textContent = message;
                }

                if (field) {
                    field.classList.add('error-field');
                    field.setAttribute('aria-invalid', 'true');
                }

                isValid = false;
            };

            if (formType === 'login') {
                clearError('account_type');
                clearError('identifier');
                clearError('password');

                const accountType = form.querySelector('[name="account_type"]');
                const identifier = form.querySelector('[name="identifier"]');
                const password = form.querySelector('[name="password"]');

                if (!accountType || accountType.value === '') {
                    setError('account_type', 'Please select an account type.');
                }

                if (!identifier || identifier.value.trim() === '') {
                    setError('identifier', 'Email or phone number is required.');
                }

                if (!password || password.value.trim() === '') {
                    setError('password', 'Password is required.');
                }

                if (isValid) {
                    form.submit();
                    return;
                }
            }

            if (formType === 'register') {
                const fields = ['first_name', 'last_name', 'phone', 'email', 'password', 'confirm_password'];
                fields.forEach(clearError);

                const firstName = form.querySelector('[name="first_name"]');
                const lastName = form.querySelector('[name="last_name"]');
                const phone = form.querySelector('[name="phone"]');
                const email = form.querySelector('[name="email"]');
                const password = form.querySelector('[name="password"]');
                const confirmPassword = form.querySelector('[name="confirm_password"]');

                if (!firstName || firstName.value.trim() === '') setError('first_name', 'First name is required.');
                if (!lastName || lastName.value.trim() === '') setError('last_name', 'Last name is required.');
                if (!phone || phone.value.trim() === '') setError('phone', 'Phone number is required.');

                if (!email || email.value.trim() === '') {
                    setError('email', 'Email address is required.');
                } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
                    setError('email', 'Please enter a valid email address.');
                }

                if (!password || password.value.trim() === '') {
                    setError('password', 'Password is required.');
                } else if (password.value.trim().length < 8) {
                    setError('password', 'Password must be at least 8 characters long.');
                }

                if (!confirmPassword || confirmPassword.value.trim() === '') {
                    setError('confirm_password', 'Please confirm your password.');
                } else if (password && confirmPassword.value !== password.value) {
                    setError('confirm_password', 'Passwords do not match.');
                }

                if (isValid) {
                    form.submit();
                    return;
                }
            }

            if (formType === 'forgot-password') {
                clearError('identifier');
                const identifier = form.querySelector('[name="identifier"]');

                if (!identifier || identifier.value.trim() === '') {
                    setError('identifier', 'Email or phone number is required.');
                }

                if (isValid) {
                    form.submit();
                    return;
                }
            }
        });
    });
});
