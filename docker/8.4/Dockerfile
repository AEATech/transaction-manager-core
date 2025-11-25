FROM php:8.4.11-cli-bullseye

RUN apt-get update && \
    apt-get install -y libzip-dev wget git libssh2-1

# Install PHP pecl extensions
RUN pecl install zip && \
    pecl install xdebug

# Enable extensions
RUN docker-php-ext-enable zip xdebug

# Install composer
ADD ./composer-installer.sh /usr/bin/composer-installer.sh
RUN chmod 0775 /usr/bin/composer-installer.sh && \
    /usr/bin/composer-installer.sh && \
    rm -rf /usr/bin/composer-installer.sh && \
    mv composer.phar /usr/bin/composer && \
    chmod 0775 /usr/bin/composer

ARG OWNER_USER
ARG OWNER_USER_ID

# Add owner user if not exists
RUN /bin/bash -c 'useradd -u ${OWNER_USER_ID} ${OWNER_USER}'

# Init dev env
RUN mkdir -p /opt/phpstorm-coverage /home/${OWNER_USER} && \
    chown ${OWNER_USER}:${OWNER_USER} /opt/phpstorm-coverage /home/${OWNER_USER}

# Change user
USER ${OWNER_USER}
