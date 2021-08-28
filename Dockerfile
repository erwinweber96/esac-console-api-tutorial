FROM php:7.3-alpine
RUN mkdir -p /usr/src/app
WORKDIR /usr/src/app
COPY . /usr/src/app

RUN apk update \
&& apk add --no-cache unzip wget ca-certificates \
&& apk add bash \
&& apk add curl

ENV HOST 0.0.0.0

CMD php controller.php