FROM php:8.2-apache

# 安装扩展
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libxml2-dev \
    && docker-php-ext-install curl dom xml

# 开启重写模块
RUN a2enmod rewrite

# 设置工作目录
WORKDIR /var/www/html

# 【重要】复制当前目录所有文件到容器根目录
COPY . /var/www/html/

# 权限设置
RUN chown -R www-data:www-data /var/www/html

# 暴露端口 80
EXPOSE 80
