FROM php:5.6-cli

ENV DEBIAN_FRONTEND=noninteractive

# skip postinstall script which starts the MySQL server
RUN echo 'deb [trusted=yes] http://archive.debian.org/debian stretch main' > /etc/apt/sources.list && \
  echo 'deb [trusted=yes] http://archive.debian.org/debian-security stretch/updates main' >> /etc/apt/sources.list && \
  echo 'deb [trusted=yes] http://repo.mysql.com/apt/debian/ stretch mysql-5.7' >> /etc/apt/sources.list && \
  apt-get update && \
  apt-get install -y --allow-unauthenticated wget cron && \
  apt-get install -y --allow-unauthenticated --download-only mysql-community-server && \
  dpkg --unpack /var/cache/apt/archives/mysql-community-server*.deb && \
  rm -f /var/lib/dpkg/info/mysql-community-server.postinst && \
  apt-get install -yf --allow-unauthenticated && \
  rm -rf /var/lib/apt/lists/* && \
  useradd --system --no-create-home --shell /bin/false mysql && \
  mkdir -p /var/lib/mysql-files && chown mysql:mysql /var/lib/mysql-files && \
  mkdir -p /var/run/mysqld && chown mysql:mysql /var/run/mysqld

RUN docker-php-ext-install mysql mysqli

COPY mysql-custom.cnf /etc/mysql/conf.d/custom.cnf
COPY download.php /app/download.php
COPY sync.sh /app/sync.sh
COPY entrypoint.sh /entrypoint.sh
COPY setup.sql /app/setup.sql
COPY api.php /app/api.php
RUN chmod +x /app/sync.sh /entrypoint.sh

VOLUME ["/var/lib/mysql"]

WORKDIR /app
EXPOSE 3307
EXPOSE 3308
ENTRYPOINT ["/entrypoint.sh"]
