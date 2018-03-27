<?php

use Illuminate\Support\Facades\Config;
use Muchrm\Graylog\Facades\Graylog;

include __DIR__.'/TestGraylogTransport.php';

class GraylogTest extends AbstractTest
{
    /**
     * Tests adding additional transports.
     */
    public function testTransport()
    {
        // Mock the null transport and add it to the transport stack in the publisher
        $transportStub = $this->getMockBuilder(\Gelf\Transport\TcpTransport::class)
            ->setMethods(['send'])
            ->getMock();
        Graylog::addTransportToPublisher($transportStub);

        // Expect the stub to be called
        $transportStub->expects($this->once())
            ->method('send')
            ->with($this->isInstanceOf(\Gelf\Message::class));

        Graylog::log('emergency', 'test', []);
    }

    /**
     * Tests the generation of a GELF message.
     */
    public function testMessageGeneration()
    {
        $self = $this;
        $testTransport = new TestGraylogTransport(function (\Gelf\MessageInterface $message) use ($self) {
            $self->assertEquals('test', $message->getShortMessage());
            $self->assertEquals('error', $message->getLevel());
        });

        Graylog::addTransportToPublisher($testTransport);
        Graylog::log('error', 'test', []);
    }

    /**
     * Tests the generation of a GELF message.
     */
    public function testException()
    {
        // Set additional fields
        Graylog::registerProcessor(new \Muchrm\Graylog\Processor\ExceptionProcessor());

        $e = new \Exception('test Exception', 300);

        $self = $this;
        $testTransport = new TestGraylogTransport(function (\Gelf\MessageInterface $message) use ($self, $e) {
            $self->assertEquals($e->getLine(), $message->getLine());
        });

        Graylog::addTransportToPublisher($testTransport);
        Graylog::logException($e);
    }

    /**
     * Tests the generation of a message with a request.
     */
    public function testRequest()
    {
        // Set additional fields
        Graylog::registerProcessor(new \Muchrm\Graylog\Processor\RequestProcessor());

        $self = $this;
        $testTransport = new TestGraylogTransport(function (\Gelf\MessageInterface $message) use ($self) {
            $self->assertEquals('http://localhost', $message->getAdditional('request_url'));
            $self->assertEquals('GET', $message->getAdditional('request_method'));
            $self->assertEquals('127.0.0.1', $message->getAdditional('request_ip'));
        });

        Graylog::addTransportToPublisher($testTransport);
        Graylog::log('error', 'test', [
            'request' => request(),
        ]);
    }

    /**
     * Tests the logging of a raw test message.
     */
    public function testRawGelfMessage()
    {
        // Set additional fields
        $self = $this;
        $testTransport = new TestGraylogTransport(function (\Gelf\MessageInterface $message) use ($self) {
            $self->assertEquals('Test Message', $message->getShortMessage());
        });

        Graylog::addTransportToPublisher($testTransport);

        $message = new \Gelf\Message();
        $message->setShortMessage('Test Message');

        Graylog::logGelfMessage($message);
    }

    public function testRequestProcessorParameters()
    {
        Graylog::registerProcessor(new \Muchrm\Graylog\Processor\RequestProcessor());

        $self = $this;
        $testTransport = new TestGraylogTransport(function (\Gelf\MessageInterface $message) use ($self) {
            $self->assertEquals('{"test":true}', $message->getAdditional('request_get_data'));
            $self->assertEquals('{"test_post":true}', $message->getAdditional('request_post_data'));
            $self->assertEquals('http://localhost', $message->getAdditional('request_url'));
            $self->assertEquals('GET', $message->getAdditional('request_method'));
            $self->assertEquals('127.0.0.1', $message->getAdditional('request_ip'));
        });
        Graylog::addTransportToPublisher($testTransport);

        // Enable get and post data logging
        Config::set('graylog.log_request_get_data', true);
        Config::set('graylog.log_request_post_data', true);

        $request = request();
        $request->query->set('test', true);
        $request->request->set('test_post', true);

        // Check if we filter out the username
        $request->request->set('username', 'henk');

        Graylog::log('error', 'test', []);
    }

    /**
     * Tests checking the Graylog transport configuration value.
     */
    public function testInvalidTransportConfiguration()
    {
        Config::set('graylog.connection.type', 'INVALID');
        $this->expectException(\DomainException::class);
        Graylog::log('emergency', 'test', []);
    }
}