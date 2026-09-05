# Anorm's PHP 7.4 development and test runtime.
#
# Modelled on the my.saygoweb.com docker/ stack, with the differences that come
# from Anorm being a library rather than an application:
#
#   * php:7.4-cli, not -apache. There is no document root, no vhost and no
#     published HTTP port; the container exists so the host can run PHPUnit,
#     phpcs and phpstan against a real MariaDB.
#   * 7.4 is the floor Anorm supports (see composer.json), and the version the
#     CI matrix has been quietly failing on — so it is what this image defaults
#     to. Override PHP_VARIANT to check a newer interpreter with the same
#     checkout: PHP_VARIANT=8.3-cli docker/anorm up --build
#   * Xdebug is pinned rather than floating. `pecl install xdebug` resolves to
#     3.4+, which requires PHP 8 and fails the build outright on 7.4.
#
# A "dev" user is remapped to the host UID/GID so vendor/ and build/ written
# inside the container come back owned by the host user.

ARG PHP_VARIANT=7.4-cli
FROM php:${PHP_VARIANT}

# Host user to own everything written into the bind-mounted workspace.
ARG USER_UID=1000
ARG USER_GID=1000
ARG USERNAME=dev

# The last Xdebug release that supports PHP 7.4. Ignored on PHP 8 variants,
# which take the current release instead — see the pecl invocation below.
ARG XDEBUG_74_VERSION=3.1.6

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        zip \
        default-mysql-client \
        libonig-dev \
        libzip-dev \
    && apt-get clean -y && rm -rf /var/lib/apt/lists/*

# pdo_mysql is what Anorm itself uses; mysqli is here because consumer code and
# the scaffolding tools in tools/ have historically reached for it.
RUN docker-php-ext-install -j"$(nproc)" mysqli pdo pdo_mysql mbstring bcmath zip

# 7.4 needs a pinned Xdebug; 8.x can take whatever pecl considers current.
RUN set -eux; \
    php_major_minor="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"; \
    if [ "${php_major_minor}" = "7.4" ]; then \
        pecl install "xdebug-${XDEBUG_74_VERSION}"; \
    else \
        pecl install xdebug; \
    fi; \
    docker-php-ext-enable xdebug

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Remap (or create) a user matching the host UID/GID. Reusing an id that already
# exists in the base image would silently hand file ownership to the wrong user,
# so rename in that case rather than adding a second account.
RUN set -eux; \
    if [ "${USER_UID}" != "0" ]; then \
        if getent group "${USER_GID}" >/dev/null; then \
            groupmod -n "${USERNAME}" "$(getent group "${USER_GID}" | cut -d: -f1)"; \
        else \
            groupadd --gid "${USER_GID}" "${USERNAME}"; \
        fi; \
        if getent passwd "${USER_UID}" >/dev/null; then \
            usermod -l "${USERNAME}" -d "/home/${USERNAME}" -m -g "${USER_GID}" \
                "$(getent passwd "${USER_UID}" | cut -d: -f1)"; \
        else \
            useradd --uid "${USER_UID}" --gid "${USER_GID}" --create-home --shell /bin/bash "${USERNAME}"; \
        fi; \
    fi

COPY php.ini /usr/local/etc/php/php.ini

# docker-php-ext-enable wrote a bare xdebug config; replace it with ours rather
# than leaving both in place, which makes PHP complain the extension is loaded twice.
RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
COPY xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Pre-create the Composer home so the named cache volume inherits its ownership.
ENV COMPOSER_HOME=/home/${USERNAME}/.composer \
    COMPOSER_MEMORY_LIMIT=-1 \
    APP_USER=${USERNAME}
RUN mkdir -p "${COMPOSER_HOME}" && chown -R "${USER_UID}:${USER_GID}" "/home/${USERNAME}"

COPY scripts/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /workspace

ENTRYPOINT ["/usr/local/bin/entrypoint"]
# A library has nothing to serve, so the container just stays up and waits for
# `docker/anorm test`, `shell`, and friends to exec into it.
CMD ["sleep", "infinity"]
